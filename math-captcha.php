<?php
// math-captcha.php
session_start();

// Generate simple operands
$a = random_int(10, 99);   // 2-digit numbers (harder for OCR than single digit)
$b = random_int(1, 9);

$_SESSION['math_captcha_answer'] = $a + $b;

// Create image
$width  = 140;
$height = 40;

$img = imagecreatetruecolor($width, $height);

// Colors
$bg      = imagecolorallocate($img, 240, 240, 240);
$textCol = imagecolorallocate($img, 20, 20, 20);
$noise   = imagecolorallocate($img, 180, 180, 180);

// Background
imagefilledrectangle($img, 0, 0, $width, $height, $bg);

// Add some noise (lines/dots) to make OCR harder
for ($i = 0; $i < 5; $i++) {
    imageline(
        $img,
        random_int(0, $width),
        random_int(0, $height),
        random_int(0, $width),
        random_int(0, $height),
        $noise
    );
}
for ($i = 0; $i < 40; $i++) {
    imagesetpixel($img, random_int(0, $width), random_int(0, $height), $noise);
}

// Text: "a + b = ?"
$text = "{$a} + {$b} = ?";
$fontSize = 5; // built-in font
$textWidth  = imagefontwidth($fontSize) * strlen($text);
$textHeight = imagefontheight($fontSize);

$x = (int)(($width  - $textWidth)  / 2);
$y = (int)(($height - $textHeight) / 2);

// Draw text
imagestring($img, $fontSize, $x, $y, $text, $textCol);

// Output
header('Content-Type: image/png');
header('Cache-Control: no-cache, no-store, must-revalidate');
imagepng($img);
imagedestroy($img);
?>
