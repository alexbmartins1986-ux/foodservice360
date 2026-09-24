<?php
/* Normaliza texto pra comparar apelidos de produto, ignorando maiusculas,
   acentos e espaços extras. "Cerveja Amstel  600ml" e "cerveja amstel 600ml"
   viram a mesma coisa. */
function normalizarTexto(string $t): string {
    $t = function_exists('mb_strtolower') ? mb_strtolower(trim($t), 'UTF-8') : strtolower(trim($t));
    $t = preg_replace('/\s+/', ' ', $t);
    $troca = ['á'=>'a','à'=>'a','ã'=>'a','â'=>'a','é'=>'e','ê'=>'e','í'=>'i',
              'ó'=>'o','ô'=>'o','õ'=>'o','ú'=>'u','ç'=>'c'];
    return strtr($t, $troca);
}
