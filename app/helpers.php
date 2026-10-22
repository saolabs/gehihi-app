<?php

function ghText(string $locale, string $vi, string $en): string
{
    return $locale === 'en' ? $en : $vi;
}
