<?php
declare(strict_types=1);
/**
 * School emblems are public, Admin-uploaded, non-executable image files.
 * Store only a generated basename in the database. Never trust a supplied URL.
 */
function school_logo_url(?string $filename):?string {
    $filename=trim((string)$filename);
    return preg_match('/^school-[0-9]{1,15}-[a-f0-9]{24}\.(?:png|jpg|webp)$/D',$filename)
        ?'/bang1/run/uploads/school-logos/'.$filename:null;
}
/** Reuse the athletics school's canonical name and optional nickname safely. */
function school_logo_key(string $value):string {
    $value=trim($value);
    $value=preg_replace('/^โรงเรียน/u','',$value)??$value;
    $value=preg_replace('/[\s\p{P}\p{Z}]+/u','',$value)??$value;
    return mb_strtolower_exists($value);
}
function mb_strtolower_exists(string $text):string {
    return function_exists('mb_strtolower')?mb_strtolower($text,'UTF-8'):strtolower($text);
}
function school_logo_lookup(array $rows):array {
    $logos=[];
    foreach($rows as $row){
        $url=school_logo_url((string)($row['logo_file']??''));
        if(!$url)continue;
        foreach(['name','nickname'] as $field){
            $key=school_logo_key((string)($row[$field]??''));
            if($key!=='')$logos[$key]=$url;
        }
    }
    return $logos;
}
function school_logo_for(array $lookup,string $name):?string {
    $key=school_logo_key($name);
    return $key!==''?($lookup[$key]??null):null;
}
