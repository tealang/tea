<?php
namespace tea\examples;

require_once __DIR__ . '/__unit.php';

// ---------
$tmp_path = dirname(__DIR__, 2) . '/tmp/';
$fontfile = $tmp_path . 'SourceHanSansSC-Regular.otf';
if (!file_exists($fontfile)) {
	echo "The font file '{$fontfile}' not found.\nPlease download it and put to path {$fontfile}", NL;
	exit;
}

$w = 629;
$h = $w;
$im = imagecreatetruecolor($w, $h);

$black = imagecolorallocatealpha($im, 0, 0, 0, 0);
$non_color = imagecolorallocatealpha($im, 0, 0, 0, 127);
$red = imagecolorallocatealpha($im, 255, 0, 0, 0);

imagefill($im, 0, 0, $red);

$im = imagerotate($im, 45, $non_color);
$w = imagesx($im);
$h = imagesy($im);

$size = 310;
$angle = 0;
$text = '春';
$bbox = imagettfbbox($size, $angle, $fontfile, $text);
$dx = $bbox[2] + $bbox[0];
$dy = (int)abs($bbox[5] + $bbox[1]);
imagettftext($im, $size, $angle, intval((($w - $dx) / 2)), $dy + intval((($h - $dy) / 2)), $black, $fontfile, $text);

$size = 30;
$text = '新春快乐';
$bbox = imagettfbbox($size, 0, $fontfile, $text);
$dx = $bbox[2] + $bbox[0];
$dy = (int)abs($bbox[5] + $bbox[1]);
imagettftext($im, $size, 0, intval((($w - $dx) / 2)), $h - $dy - 130, $black, $fontfile, $text);

$size = 10;
$text = 'tealang';
$bbox = imagettfbbox($size, 0, $fontfile, $text);
$dx = $bbox[2] + $bbox[0];
$dy = (int)abs($bbox[5] + $bbox[1]);
imagettftext($im, $size, 0, intval((($w - $dx) / 2)), $h - $dy - 100, $black, $fontfile, $text);

$target_image_file = $tmp_path . 'chun.png';
$result = imagepng($im, $target_image_file);
imagedestroy($im);

if ($result) {
	echo 'Image ' . $target_image_file . ' generated.', NL;
}
else {
	echo 'Image ' . $target_image_file . ' generate failure.', NL;
}
// ---------

// program end
