<!-- register.php -->
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><title>Register</title></head>
<body>
  <h1>Create your GigHive account</h1>
  <form action="handle_register.php" method="post">
    <label>Email:
      <input type="email" name="email" required>
    </label><br><br>
    <label>Password:
      <input type="password" name="password" minlength="8" required>
    </label><br><br>
    <button type="submit">Register</button>
  </form>
</body>
</html>

