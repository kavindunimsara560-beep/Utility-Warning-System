<?php
$qs = !empty($_SERVER['QUERY_STRING']) ? ('&' . $_SERVER['QUERY_STRING']) : '';
header("Location: ../login.php?role=customer" . $qs);
exit();
