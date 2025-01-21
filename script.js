// Animation controls
const wrapper = document.querySelector('.wrapper');
const registerLink = document.querySelector('.register-link');
const loginLink = document.querySelector('.login-link');

registerLink.onclick = () => {
    wrapper.classList.add('active');
}

loginLink.onclick = () => {
    wrapper.classList.remove('active');
}

// Form handling
document.addEventListener('DOMContentLoaded', function() {
    const loginForm = document.getElementById('loginForm');
    const registerForm = document.getElementById('registerForm');
    const loginMessage = document.getElementById('loginMessage');
    const registerMessage = document.getElementById('registerMessage');

    // Handle Login Form
    loginForm.addEventListener('submit', function(e) {
        e.preventDefault();
        loginMessage.style.display = 'none'; // Hide any previous message
        
        const formData = new FormData(this);
        formData.append('login', 'true'); // Make sure we're sending the login identifier
        
        fetch(window.location.href, {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => response.json())
        .then(data => {
            loginMessage.textContent = data.message;
            loginMessage.className = 'message ' + data.status;
            loginMessage.style.display = 'block';

            if (data.status === 'success') {
                // Show success message for 2 seconds before redirect
                loginMessage.style.backgroundColor = 'rgba(0, 255, 0, 0.1)';
                loginMessage.style.color = '#00cc00';
                setTimeout(() => {
                    window.location.href = data.redirect;
                }, 2000);
            } else {
                // Show error message
                loginMessage.style.backgroundColor = 'rgba(255, 0, 0, 0.1)';
                loginMessage.style.color = '#ff3333';
            }
        })
        .catch(error => {
            loginMessage.textContent = 'An error occurred. Please try again.';
            loginMessage.className = 'message error';
            loginMessage.style.display = 'block';
        });
    });

    // Handle Registration Form
    registerForm.addEventListener('submit', function(e) {
        e.preventDefault();
        registerMessage.style.display = 'none'; // Hide any previous message
        
        const formData = new FormData(this);
        formData.append('register', 'true'); // Make sure we're sending the register identifier
        
        fetch(window.location.href, {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => response.json())
        .then(data => {
            registerMessage.textContent = data.message;
            registerMessage.className = 'message ' + data.status;
            registerMessage.style.display = 'block';

            if (data.status === 'success') {
                // Show success message and switch to login form after 2 seconds
                registerMessage.style.backgroundColor = 'rgba(0, 255, 0, 0.1)';
                registerMessage.style.color = '#00cc00';
                // Clear the form
                registerForm.reset();
                setTimeout(() => {
                    loginLink.click();
                }, 2000);
            } else {
                // Show error message
                registerMessage.style.backgroundColor = 'rgba(255, 0, 0, 0.1)';
                registerMessage.style.color = '#ff3333';
            }
        })
        .catch(error => {
            registerMessage.textContent = 'An error occurred. Please try again.';
            registerMessage.className = 'message error';
            registerMessage.style.display = 'block';
        });
    });
});