<?php
namespace tea\examples;

require_once __DIR__ . '/__public.php';

function draw_text($image, string $text, int $size, int $color, string $fontfile, int $h_offset = 0, bool $is_handstand = false) {
	$w = imagesx($image);
	$h = imagesy($image);

	$angle = $is_handstand ? 180 : 0;

	$bbox = imagettfbbox($size, $angle, $fontfile, $text);
	$dx = $bbox[2] + $bbox[0];
	$dy = abs($bbox[5] + $bbox[1]);

	$center = null;
	if ($is_handstand) {
		$center = ($h - $dy) / 2;
	}
	else {
		$center = ($h + $dy) / 2;
	}

	imagettftext($image, $size, $angle, (int)(($w - $dx) / 2), (int)$center + $h_offset, $color, $fontfile, $text);
}

#internal
const BOX_WIDTH = 629;

// ---------
$tmp_path = dirname(__DIR__, 2) . '/tmp/';
$fontfile = $tmp_path . 'SourceHanSansSC-Regular.otf';
if (!file_exists($fontfile)) {
	echo "The font file '{$fontfile}' not found.\nPlease download it and put to path {$fontfile}", LF;
	exit;
}

$image = imagecreatetruecolor(BOX_WIDTH, BOX_WIDTH);

$non_color = imagecolorallocatealpha($image, 0, 0, 0, 127);
$black = imagecolorallocatealpha($image, 0, 0, 0, 0);
$red = imagecolorallocatealpha($image, 255, 0, 0, 0);

imagefill($image, 0, 0, $red);
$image = imagerotate($image, 45, $non_color);

draw_text($image, '春', 310, $black, $fontfile, 0);
draw_text($image, '新春快乐', 30, $black, $fontfile, 270);
draw_text($image, 'Tea语言', 12, $black, $fontfile, 330);

$target_image_file = $tmp_path . 'chun.png';
$result = imagepng($image, $target_image_file);
imagedestroy($image);

if ($result) {
	echo 'Image ' . $target_image_file . ' generated.', LF;
}
else {
	echo 'Image ' . $target_image_file . ' generate failure.', LF;
}
// ---------

// program end
