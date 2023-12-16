<?php
function detectCyrillic($text) {
    // Визначаємо кирилічні мови
$ukrainian = '/[А-ЩЬЮЯҐІЇЄа-щьюяґіїє]/u';
$ukrainian_specific = '/[ІЇЄіїє]/u';
$russian = '/[А-Яа-яЁёыъ]/u';
$russian_specific = '/[ЁЭёэыъ]/u';

// Рахуємо кількість кирилічних та специфічних літер для кожної мови
$ukrainian_count = preg_match_all($ukrainian, $text);
$ukrainian_specific_count = preg_match_all($ukrainian_specific, $text);
$russian_count = preg_match_all($russian, $text);
$russian_specific_count = preg_match_all($russian_specific, $text);

// Збільшуємо вагу специфічних літер
$ukrainian_count += $ukrainian_specific_count * 2;
$russian_count += $russian_specific_count * 2;

// Перевіряємо кількість кирилічних та специфічних літер для кожної мови
if ($ukrainian_count > $russian_count) {
    $language = 'uk';
} elseif ($russian_count > $ukrainian_count) {
    $language = 'ru';
} else {
    $language = null;
}

return $language;
}
?>