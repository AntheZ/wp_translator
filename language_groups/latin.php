<?php
function detectLatin($text) {
    // Визначаємо латинські мови
    $english = '/[a-zA-Z]/';
    $spanish = '/[áéíóúüñÁÉÍÓÚÜÑ]/';
    $french = '/[àâæçéèêëîïôœùûüÿÀÂÆÇÉÈÊËÎÏÔŒÙÛÜŸ]/';
    $german = '/[äöüßÄÖÜẞ]/';

    // Рахуємо кількість латинських літер
    $english_count = preg_match_all($english, $text);
    $spanish_count = preg_match_all($spanish, $text);
    $french_count = preg_match_all($french, $text);
    $german_count = preg_match_all($german, $text);

    // Перевіряємо кількість латинських літер
    $max_count = max($english_count, $spanish_count, $french_count, $german_count);
    if ($max_count > mb_strlen($text) / 2) {
        if ($max_count == $english_count) {
            $language = 'en';
        } elseif ($max_count == $spanish_count) {
            $language = 'es';
        } elseif ($max_count == $french_count) {
            $language = 'fr';
        } else {
            $language = 'de';
        }
    } else {
        $language = null;
    }

    return $language;
}
?>