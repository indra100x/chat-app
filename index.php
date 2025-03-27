<?php 
session_start();
header("Cache-Control: no-store, no-cache, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");
if(isset($_SESSION['username'])){
    header("location:chats.php");
    exit();
}else{
    header("location: login.php");
}
?>