<?php
ob_start(); 
session_start();
include 'include/dbi.php';
include 'include/session.php';
//session_unset($_SESSION["login"]);
session_destroy();
header("location:index");
?>
