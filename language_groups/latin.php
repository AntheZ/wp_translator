<?php
// Визначаємо латинські мови
$english = '/[a-zA-Z]/';

// Рахуємо кількість латинських літер
$english_count = preg_match_all($english, $text);

// Перевіряємо кількість латинських літер
if ($english_count > mb_strlen($text) / 2) {
    $language = 'en';
} else {
    $language = null;
}

return $language;
?>