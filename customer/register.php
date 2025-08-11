<?php
include '../connect.php';

session_start();
$errors = $_SESSION['registration_errors'] ?? [];
unset($_SESSION['registration_errors']);

$suggested_username = $_SESSION['suggested_username'] ?? '';
unset($_SESSION['suggested_username']);
?>
<link rel="stylesheet" href="/assets/css/style.css">
<link rel="icon" type="image/png" href="/assets/images/TimeLess_logo.png">
<title>Register</title>
<style>
.password-policy {
  background: #f8faff;
  border: 1px solid #c5d1f7;
  border-radius: 7px;
  color: #28509d;
  font-size: 0.99em;
  margin-bottom: 13px;
  margin-top: 10px;
  padding: 13px 18px 11px 18px;
}
.password-policy ul {
  margin: 4px 0 0 0;
  padding-left: 23px;
}
.suggested-username {
  background: #fff7e0;
  border: 1px solid #ffe2a4;
  color: #b17b00;
  border-radius: 7px;
  font-size: 1em;
  margin-bottom: 13px;
  padding: 10px 18px 9px 18px;
}
.inline-check {
  font-size: 0.99em;
  margin-top: 2px;
  margin-bottom: 7px;
  padding-left: 2px;
}
.inline-check.invalid {
  color: #e54848;
}
.inline-check.valid {
  color: #2bbf5f;
  display: none;
}
.password-toggle {
  position: relative;
  display: flex;
  align-items: center;
}
.password-toggle input[type="password"],
.password-toggle input[type="text"] {
  flex: 1;
  padding-right: 38px;
}
.password-toggle .toggle-icon {
  position: absolute;
  right: 12px;
  cursor: pointer;
  color: #888;
  font-size: 1.1em;
  background: transparent;
  border: none;
  outline: none;
}
#password-match-message {
  font-size: 0.99em;
  margin-top: 4px;
  margin-bottom: 7px;
  color: #e54848;
  display: none;
  padding-left: 2px;
  position: static;
}
</style>
<script>
document.addEventListener("DOMContentLoaded", function() {
  function checkExist(type, value, cb) {
    if (!value) {
      cb(false);
      return;
    }
    fetch('check_user_exists.php?type=' + encodeURIComponent(type) + '&value=' + encodeURIComponent(value))
      .then(resp => resp.json())
      .then(data => cb(data.exists))
      .catch(() => cb(false));
  }

  // Username check
  const usernameInput = document.getElementById('username');
  const usernameCheck = document.createElement('div');
  usernameCheck.className = "inline-check";
  usernameInput.parentNode.insertBefore(usernameCheck, usernameInput.nextSibling);

  usernameInput.addEventListener('input', function() {
    const value = usernameInput.value.trim();
    if (!value) {
      usernameCheck.textContent = '';
      usernameCheck.className = "inline-check";
      return;
    }
    checkExist('username', value, function(exists) {
      if (exists) {
        usernameCheck.textContent = "This username is already taken.";
        usernameCheck.className = "inline-check invalid";
      } else {
        usernameCheck.textContent = "";
        usernameCheck.className = "inline-check valid";
      }
    });
  });

  // Email format and check
  const emailInput = document.getElementById('email');
  const emailCheck = document.createElement('div');
  emailCheck.className = "inline-check";
  emailInput.parentNode.insertBefore(emailCheck, emailInput.nextSibling);

  function validateEmailFormat(email) {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
  }

  emailInput.addEventListener('input', function() {
    const value = emailInput.value.trim();
    if (!value) {
      emailCheck.textContent = '';
      emailCheck.className = "inline-check";
      return;
    }
    if (!validateEmailFormat(value)) {
      emailCheck.textContent = "Invalid email format.";
      emailCheck.className = "inline-check invalid";
      return;
    }
    checkExist('email', value, function(exists) {
      if (exists) {
        emailCheck.textContent = "An account with this email already exists.";
        emailCheck.className = "inline-check invalid";
      } else {
        emailCheck.textContent = "";
        emailCheck.className = "inline-check";
      }
    });
  });

  // Malaysian phone number format (10 or 11 digits, starts with 01)
  const phoneInput = document.getElementById('phone_no');
  const phoneCheck = document.createElement('div');
  phoneCheck.className = "inline-check";
  phoneInput.parentNode.insertBefore(phoneCheck, phoneInput.nextSibling);

  function validatePhoneFormat(phone) {
    phone = phone.replace(/[\s\-]/g, '');
    return /^01\d{8,9}$/.test(phone) && phone.length >= 10 && phone.length <= 11;
  }

  phoneInput.addEventListener('input', function() {
    let value = phoneInput.value.replace(/[^0-9\-]/g, ''); // Remove unwanted chars
    if (value.length > 11) value = value.slice(0, 11); // Enforce max 11 chars
    phoneInput.value = value;
    if (!value) {
      phoneCheck.textContent = '';
      phoneCheck.className = "inline-check";
      return;
    }
    if (!validatePhoneFormat(value)) {
      phoneCheck.textContent = "Invalid phone number format. Only 10 or 11 digits starting with 01. Example: 0123456789";
      phoneCheck.className = "inline-check invalid";
    } else {
      phoneCheck.textContent = "";
      phoneCheck.className = "inline-check";
    }
  });

  // Password show/hide toggle
  document.querySelectorAll('.password-toggle').forEach(function(div) {
    const input = div.querySelector('input');
    const icon = div.querySelector('.toggle-icon');
    icon.addEventListener('click', function() {
      if (input.type === "password") {
        input.type = "text";
        icon.innerHTML = '<svg width="22" height="22" viewBox="0 0 24 24"><path fill="#888" d="M12 4.5C7.305 4.5 3.135 7.305 1.5 12c.643 1.706 1.7 3.288 3.019 4.602l-1.42 1.416 1.414 1.415 18-18-1.415-1.414-2.592 2.591C15.82 4.68 13.943 4.5 12 4.5zm0 3c1.493 0 2.915.207 4.236.57L14.43 9.876A3.5 3.5 0 0 0 9.876 14.43l-2.804 2.804C4.207 14.915 4 13.493 4 12c0-2.485 3.515-7.5 8-7.5zm5.926 2.008l-1.415 1.415c.405.81.813 1.653 1.229 2.574C20.793 14.085 21 15.507 21 17c0 2.485-3.515 7.5-8 7.5-1.943 0-3.82-.18-5.508-.491l-2.592 2.591 1.415 1.414 18-18-1.415-1.414-2.592 2.591zm-6.31 6.31a1.5 1.5 0 0 1 2.121 2.122l-2.122-2.121zm7.742-4.147c.508.894.99 1.887 1.44 2.914C19.793 17.085 20 18.507 20 20c0 2.485-3.515 7.5-8 7.5-1.943 0-3.82-.18-5.508-.491l-2.592 2.591 1.415 1.414 18-18-1.415-1.414-2.592 2.591z"/></svg>';
      } else {
        input.type = "password";
        icon.innerHTML = '<svg width="22" height="22" viewBox="0 0 24 24"><path fill="#888" d="M12 5c-7.633 0-11.2 6.397-11.381 6.707a1 1 0 0 0 0 .586C.8 12.603 4.367 19 12 19s11.2-6.397 11.381-6.707a1 1 0 0 0 0-.586C23.2 11.397 19.633 5 12 5zm0 12c-5.522 0-8.921-4.296-9.75-5C3.079 11.296 6.478 7 12 7s8.921 4.296 9.75 5c-.829.704-4.228 5-9.75 5zm0-8a3 3 0 1 0 0 6 3 3 0 0 0 0-6zm0 4a1 1 0 1 1 0-2 1 1 0 0 1 0 2z"/></svg>';
      }
    });
  });

  // Password match message
  const passwordInput = document.getElementById('password');
  const confirmPasswordInput = document.getElementById('confirm_password');
  const matchMessage = document.createElement('div');
  matchMessage.id = "password-match-message";
  const confirmParent = confirmPasswordInput.parentNode;
  if (confirmParent.classList.contains('password-toggle')) {
    confirmParent.parentNode.insertBefore(matchMessage, confirmParent.nextSibling);
  } else {
    confirmPasswordInput.parentNode.insertBefore(matchMessage, confirmPasswordInput.nextSibling);
  }

  function checkMatch() {
    if (!confirmPasswordInput.value) {
      matchMessage.style.display = "none";
      return;
    }
    if (passwordInput.value !== confirmPasswordInput.value) {
      matchMessage.textContent = "Passwords do not match.";
      matchMessage.style.display = "block";
    } else {
      matchMessage.textContent = "";
      matchMessage.style.display = "none";
    }
  }

  passwordInput.addEventListener('input', checkMatch);
  confirmPasswordInput.addEventListener('input', checkMatch);
});
</script>

<div class="register-container">
  <div class="register-box">
    <h2>Create Your Customer Account</h2>
    <?php if ($errors): ?>
      <div class="error-messages">
        <ul>
          <?php foreach ($errors as $error): ?>
            <li><?= $error // allow html for username suggestion ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>
    <?php if ($suggested_username): ?>
      <div class="suggested-username">
        Try this username: <strong><?= htmlspecialchars($suggested_username) ?></strong>
      </div>
    <?php endif; ?>
    <form action="registerprocess.php" method="post" class="register-form" enctype="multipart/form-data" autocomplete="off">
      <label for="email">Email</label>
      <input type="email" id="email" name="email" required pattern="^[^\s@]+@[^\s@]+\.[^\s@]+$" title="Please enter a valid email address">
      <label for="username">Username</label>
      <input type="text" id="username" name="username" required>
      <label for="phone_no">Phone Number</label>
      <input type="text" id="phone_no" name="phone_no"
        required
        pattern="^01\d{8,9}$"
        maxlength="11"
        title="Format: Only 10 or 11 digits starting with 01. Example: 0123456789 or 01112345678">
      <label for="password">Password</label>
      <div class="password-toggle">
        <input type="password" id="password" name="password" required autocomplete="new-password">
        <button type="button" tabindex="-1" class="toggle-icon" aria-label="Show/Hide password" style="background:none;border:none;padding:0;">
          <svg width="22" height="22" viewBox="0 0 24 24"><path fill="#888" d="M12 5c-7.633 0-11.2 6.397-11.381 6.707a1 1 0 0 0 0 .586C.8 12.603 4.367 19 12 19s11.2-6.397 11.381-6.707a1 1 0 0 0 0-.586C23.2 11.397 19.633 5 12 5zm0 12c-5.522 0-8.921-4.296-9.75-5C3.079 11.296 6.478 7 12 7s8.921 4.296 9.75 5c-.829.704-4.228 5-9.75 5zm0-8a3 3 0 1 0 0 6 3 3 0 0 0 0-6zm0 4a1 1 0 1 1 0-2 1 1 0 0 1 0 2z"/></svg>
        </button>
      </div>
      <div class="password-policy">
        <strong>Password must meet the following requirements:</strong>
        <ul>
          <li>At least 8 characters long</li>
          <li>Contains at least one uppercase letter (A-Z)</li>
          <li>Contains at least one lowercase letter (a-z)</li>
          <li>Contains at least one number (0-9)</li>
          <li>Contains at least one special character (e.g. !@#$%^&amp;*)</li>
        </ul>
      </div>
      <label for="confirm_password">Confirm Password</label>
      <div class="password-toggle">
        <input type="password" id="confirm_password" name="confirm_password" required autocomplete="new-password">
        <button type="button" tabindex="-1" class="toggle-icon" aria-label="Show/Hide password" style="background:none;border:none;padding:0;">
          <svg width="22" height="22" viewBox="0 0 24 24"><path fill="#888" d="M12 5c-7.633 0-11.2 6.397-11.381 6.707a1 1 0 0 0 0 .586C.8 12.603 4.367 19 12 19s11.2-6.397 11.381-6.707a1 1 0 0 0 0-.586C23.2 11.397 19.633 5 12 5zm0 12c-5.522 0-8.921-4.296-9.75-5C3.079 11.296 6.478 7 12 7s8.921 4.296 9.75 5c-.829.704-4.228 5-9.75 5zm0-8a3 3 0 1 0 0 6 3 3 0 0 0 0-6zm0 4a1 1 0 1 1 0-2 1 1 0 0 1 0 2z"/></svg>
        </button>
      </div>
      <!-- The password-match-message will be inserted below the confirm password box by JS -->
      <button type="submit" class="register-btn">Register</button>
    </form>
    <div class="login-link">
      Already have an account? <a href="/index.php">Login here</a>.
    </div>
  </div>
</div>

<?php include '../includes/footer.php'; ?>