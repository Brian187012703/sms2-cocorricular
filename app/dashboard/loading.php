<?php
session_start();
if (empty($_SESSION['user_id'])) {
    header('Location: ../auth/signin.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Loading – BCP Co-Curricular Portal</title>
  <link rel="stylesheet" href="../css/loading.css" />
</head>
<body>
  <img class="bcp-logo" src="../images/BCP_LOGO.png" alt="Bestlink College of the Philippines Logo" />
  <p class="welcome">Magandang Buhay BCPian!</p>
  <p class="wait">
    Please wait
    <span class="dots"><span></span><span></span><span></span></span>
  </p>
  <script>
    setTimeout(function () {
      window.location.href = 'dashboard.php';
    }, 2500);
  </script>
</body>
</html>
