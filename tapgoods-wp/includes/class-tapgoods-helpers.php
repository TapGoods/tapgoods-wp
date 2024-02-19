<?php 

class Tapgoods_Helpers {
    public static function tgdd($data, $pre = true) {
        echo ($pre) ? "<pre>" : '';
        var_dump($data); 
        
        die();
    }
}