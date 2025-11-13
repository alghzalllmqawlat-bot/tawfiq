<?php
// auto-installer.php (نسخة عربية - مبدئية)
// الاستخدام: ارفع هذا الملف وملف setup.json إلى جذر WordPress (حيث يوجد wp-load.php).
// ثم افتح: https://your-site/auto-installer.php?key=SECRET_KEY
// تحذير: احذف هذا الملف بعد الاستخدام. احفظ نسخة احتياطية من قاعدة البيانات قبل التشغيل.

set_time_limit(0);
error_reporting(E_ALL);
ini_set('display_errors', 1);

$setupFile = __DIR__ . '/setup.json';
if (!file_exists($setupFile)) {
    echo "خطأ: لم يتم العثور على setup.json. ضع الملف بجانب هذا السكربت.\n";
    exit;
}

$setup = json_decode(file_get_contents($setupFile), true);
if (!$setup) {
    echo "خطأ: ملف setup.json غير صالح أو ليس JSON.\n";
    exit;
}

$secret = $setup['secret'] ?? '';
$got = $_GET['key'] ?? '';
if (!$secret || $got !== $secret) {
    echo "خطأ: المفتاح السري مفقود أو غير صحيح.\n";
    exit;
}

// البحث عن wp-load.php لتهيئة بيئة ووردبريس
$wp_load = __DIR__ . '/wp-load.php';
if (!file_exists($wp_load)) {
    echo "خطأ: لم يتم العثور على wp-load.php. تأكد أن WordPress مثبت في هذا المجلد.\n";
    exit;
}

require_once $wp_load;
if (!function_exists('activate_plugin')) {
    echo "خطأ: دوال WordPress غير متاحة.\n";
    exit;
}

echo "<pre>";
echo "بدء تشغيل مثبت WordPress الآلي...\n";

function download_and_extract($url, $destDir) {
    $tmp = tempnam(sys_get_temp_dir(), 'dl_');
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    $data = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err) {
        return "CURL ERROR: $err";
    }
    if (!$data) return "التحميل أعاد محتوى فارغ";
    file_put_contents($tmp, $data);
    $zip = new ZipArchive;
    if ($zip->open($tmp) === TRUE) {
        $res = $zip->extractTo($destDir);
        $zip->close();
        unlink($tmp);
        return $res ? true : "فشل استخراج الأرشيف";
    } else {
        unlink($tmp);
        return "فشل فتح ملف ZIP";
    }
}

$wpContent = WP_CONTENT_DIR;
echo "مسار wp-content: $wpContent\n";

// 1) تثبيت القالب (يمكن أن يكون slug أو رابط ZIP)
if (!empty($setup['theme'])) {
    $theme = $setup['theme'];
    echo "تثبيت القالب: $theme\n";
    if (filter_var($theme, FILTER_VALIDATE_URL)) {
        $r = download_and_extract($theme, $wpContent . '/themes');
        echo "نتيجة تحميل القالب: " . var_export($r, true) . "\n";
    } else {
        $url = "https://downloads.wordpress.org/theme/{$theme}.zip";
        $r = download_and_extract($url, $wpContent . '/themes');
        echo "نتيجة تحميل قالب من WordPress.org: " . var_export($r, true) . "\n";
    }
    if (is_dir($wpContent . '/themes/' . $theme)) {
        switch_theme($theme);
        echo "تم تفعيل القالب: $theme\n";
    } else {
        $dirs = array_diff(scandir($wpContent . '/themes', SCANDIR_SORT_DESCENDING), array('.', '..'));
        if (!empty($dirs)) {
            foreach ($dirs as $d) {
                if (is_dir($wpContent . '/themes/' . $d)) {
                    switch_theme($d);
                    echo "تم تفعيل قالب (محاولة تلقائية): $d\n";
                    break;
                }
            }
        } else {
            echo "تعذّر العثور على مجلد القالب لتفعيله.\n";
        }
    }
}

// 2) تثبيت الإضافات
if (!empty($setup['plugins']) && is_array($setup['plugins'])) {
    foreach ($setup['plugins'] as $p) {
        echo "معالجة الإضافة: $p\n";
        if (filter_var($p, FILTER_VALIDATE_URL)) {
            $res = download_and_extract($p, $wpContent . '/plugins');
            echo "نتيجة تنزيل الإضافة من رابط: " . var_export($res, true) . "\n";
        } else {
            $url = "https://downloads.wordpress.org/plugin/{$p}.zip";
            $res = download_and_extract($url, $wpContent . '/plugins');
            echo "نتيجة تنزيل الإضافة {$p}: " . var_export($res, true) . "\n";
        }
        $pluginMainFile = null;
        $pluginFolder = $wpContent . '/plugins/' . $p;
        if (is_dir($pluginFolder)) {
            $files = scandir($pluginFolder);
            foreach ($files as $f) {
                if (preg_match('/\.php$/i', $f)) {
                    $pluginMainFile = $p . '/' . $f;
                    break;
                }
            }
        } else {
            $folders = array_diff(scandir($wpContent . '/plugins', SCANDIR_SORT_DESCENDING), array('.', '..'));
            foreach ($folders as $f) {
                if (is_dir($wpContent . '/plugins/' . $f)) {
                    $fs = scandir($wpContent . '/plugins/' . $f);
                    foreach ($fs as $ff) {
                        if (preg_match('/\.php$/i', $ff)) {
                            $pluginMainFile = $f . '/' . $ff;
                            break 2;
                        }
                    }
                }
            }
        }
        if ($pluginMainFile) {
            $resAct = activate_plugin($pluginMainFile);
            if (is_wp_error($resAct)) {
                echo "خطأ تفعيل الإضافة {$pluginMainFile}: " . $resAct->get_error_message() . "\n";
            } else {
                echo "تم تفعيل الإضافة: {$pluginMainFile}\n";
            }
        } else {
            echo "تعذّر تحديد ملف الإضافة الرئيسي للتفعيل؛ قد تحتاج لتفعيلها يدويًا من لوحة التحكم.\n";
        }
    }
}

// 3) إنشاء الصفحات
if (!empty($setup['pages']) && is_array($setup['pages'])) {
    foreach ($setup['pages'] as $pg) {
        $title = $pg['title'] ?? 'صفحة';
        $content = $pg['content'] ?? '';
        $exists = get_page_by_title($title, OBJECT, 'page');
        if ($exists) {
            echo "الصفحة موجودة: $title — سيتم التجاهل.\n";
        } else {
            $post_id = wp_insert_post(array(
                'post_title' => $title,
                'post_content' => $content,
                'post_status' => 'publish',
                'post_type' => 'page'
            ));
            if (is_wp_error($post_id)) {
                echo "خطأ أثناء إدراج الصفحة $title: " . $post_id->get_error_message() . "\n";
            } else {
                echo "تم إنشاء الصفحة: $title (ID $post_id)\n";
            }
        }
    }
}

// 4) تطبيق الإعدادات الأساسية
if (!empty($setup['settings']) && is_array($setup['settings'])) {
    foreach ($setup['settings'] as $k => $v) {
        update_option($k, $v);
        echo "تم تحديث الإعداد: $k\n";
    }
}

echo "انتهى مثبت WordPress الآلي.\n</pre>";
?>