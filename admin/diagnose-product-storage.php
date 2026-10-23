<?php
/** Owner-only CLI diagnostics. No database access, uploaded customer files or persistent fixtures. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI access only.'); }
if ($argc > 2 || !in_array($argv[1] ?? '--check', ['--check','--write-probe'], true)) {
    fwrite(STDERR, "Usage: php admin/diagnose-product-storage.php [--check|--write-probe]\n"); exit(1);
}
require_once dirname(__DIR__).'/includes/product_uploads.php';
$directory = dirname(__DIR__).'/assets/products';
$output = ['scope'=>'CLI_IDENTITY_ONLY_NOT_APACHE_HTTP_OR_MYSQL', 'context'=>product_photo_storage_context($directory), 'probes'=>[]];
if (($argv[1] ?? '--check') === '--check') { echo json_encode($output, JSON_PRETTY_PRINT)."\n"; exit; }
$tempDirectory = null; $owned = []; $failed = false;
try {
    if (!extension_loaded('gd')) throw new RuntimeException('GD unavailable');
    $tempDirectory = sys_get_temp_dir().'/techtalk-storage-probe-'.bin2hex(random_bytes(6));
    if (!@mkdir($tempDirectory,0700)) throw new RuntimeException('Temporary storage unavailable');
    $canvas = imagecreatetruecolor(64,48);
    imagefill($canvas,0,0,imagecolorallocate($canvas,24,110,160));
    imagepng($canvas,$tempDirectory.'/source.png'); imagejpeg($canvas,$tempDirectory.'/source.jpg',85); imagedestroy($canvas);
    file_put_contents($tempDirectory.'/source.webp',base64_decode('UklGRiIAAABXRUJQVlA4IBYAAAAwAQCdASoBAAEAAUAmJaQAA3AA/v89WAAAAA==',true));
    $jobs = array_merge(array_fill(0,6,'png'),['jpg','webp']);
    foreach ($jobs as $i=>$format) {
        $destination = null; $stage = 'probe_inspection'; error_clear_last();
        try {
            $source = $tempDirectory.'/source.'.$format;
            $info = product_photo_inspect($source,filesize($source));
            $extension = $info['mime'] === 'image/webp' && !function_exists('imagewebp') ? 'png' : $info['extension'];
            $stage = 'probe_storage_reservation';
            $reserved = product_photo_reserve($directory,$extension);
            $destination = $reserved['path']; $owned[] = $destination;
            $stage = 'probe_image_encoding'; product_photo_encode($source,$info['mime'],$destination);
            $stage = 'probe_thumbnail_encoding';
            if (!product_photo_thumbnail($destination,$destination.'.thumb.jpg')) throw new RuntimeException('Thumbnail failed');
            $output['probes'][] = ['case'=>$format.'-'.($i+1),'encoded'=>true,'thumbnail'=>true];
        } catch (Throwable $e) {
            $failed = true; $ref = bin2hex(random_bytes(4));
            product_photo_log_failure($ref,$stage,$directory,error_get_last());
            $output['probes'][] = ['case'=>$format.'-'.($i+1),'encoded'=>false,'stage'=>$stage,'reference'=>$ref];
        } finally {
            if ($destination) {
                foreach ([$destination,$destination.'.thumb.jpg'] as $file) if (is_file($file)) @unlink($file);
                if (is_file($destination) || is_file($destination.'.thumb.jpg')) $failed = true;
            }
        }
    }
} catch (Throwable $e) {
    $failed = true; $ref = bin2hex(random_bytes(4));
    product_photo_log_failure($ref,'probe_setup',$directory,error_get_last());
    $output['setup_error_reference'] = $ref;
} finally {
    if ($tempDirectory && is_dir($tempDirectory)) { foreach (glob($tempDirectory.'/*') as $file) @unlink($file); @rmdir($tempDirectory); }
    $output['owned_files_remaining'] = count(array_filter($owned,fn($p)=>is_file($p)||is_file($p.'.thumb.jpg')));
}
$output['verdict'] = $failed ? 'CLI_STORAGE_PROBE_FAILED' : 'CLI_STORAGE_PROBE_PASSED';
echo json_encode($output,JSON_PRETTY_PRINT)."\n";
exit($failed ? 2 : 0);
