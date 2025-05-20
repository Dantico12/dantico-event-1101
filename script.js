// DOM Elements
const wrapper = document.querySelector('.wrapper');
const registerLink = document.querySelector('.register-link');
const loginLink = document.querySelector('.login-link');
const loginForm = document.getElementById('loginForm');
const registerForm = document.getElementById('registerForm');
const loginMessage = document.getElementById('loginMessage');
const registerMessage = document.getElementById('registerMessage');

// Get CSRF token
const csrfToken = document.querySelector('meta[name="csrf-token"]').content;

// Toggle between forms
registerLink.onclick = (e) => {
    e.preventDefault();
    wrapper.classList.add('active');
    registerMessage.style.display = 'none';
};

loginLink.onclick = (e) => {
    e.preventDefault();
    wrapper.classList.remove('active');
    loginMessage.style.display = 'none';
};

// Real-time input validation
function setupInputValidation() {
    // Username validation
    const usernameInputs = document.querySelectorAll('input[name="username"]');
    usernameInputs.forEach(input => {
        input.addEventListener('input', function() {
            this.value = this.value.replace(/[^a-zA-Z0-9_\-.]/g, '');
            if (this.value.length > 20) {
                this.value = this.value.slice(0, 20);
            }
        });
    });

    // Password strength indicator
    const passwordInputs = document.querySelectorAll('input[name="password"]');
    passwordInputs.forEach(input => {
        input.addEventListener('input', function() {
            const strength = calculatePasswordStrength(this.value);
            updatePasswordStrengthIndicator(strength, this);
        });
    });
}

function calculatePasswordStrength(password) {
    let strength = 0;
    if (password.length >= 8) strength++;
    if (password.length >= 12) strength++;
    if (/[A-Z]/.test(password)) strength++;
    if (/[0-9]/.test(password)) strength++;
    if (/[^A-Za-z0-9]/.test(password)) strength++;
    return Math.min(strength, 5);
}

function updatePasswordStrengthIndicator(strength, input) {
    let indicator = input.nextElementSibling;
    if (!indicator || !indicator.classList.contains('password-strength')) {
        indicator = document.createElement('div');
        indicator.className = 'password-strength';
        input.parentNode.appendChild(indicator);
    }
    
    indicator.innerHTML = '';
    for (let i = 0; i < 5; i++) {
        const bar = document.createElement('div');
        bar.className = i < strength ? 'strength-bar active' : 'strength-bar';
        indicator.appendChild(bar);
    }
}

// Form submission handlers
function setupFormHandlers() {
    // Login form
    loginForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        loginMessage.style.display = 'none';
        
        const formData = new FormData(loginForm);
        formData.append('login', 'true');

        // Client-side validation
        const username = formData.get('username');
        const password = formData.get('password');
        
        if (!username || username.length < 3) {
            showError(loginMessage, 'Username must be at least 3 characters');
            return;
        }
        
        if (!password || password.length < 8) {
            showError(loginMessage, 'Password must be at least 8 characters');
            return;
        }

        try {
            const response = await fetch(window.location.href, {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                },
                credentials: 'same-origin'
            });
            
            const data = await response.json();
            showMessage(loginMessage, data.message, data.status);
            
            if (data.status === 'success') {
                setTimeout(() => {
                    window.location.href = data.redirect || 'dashboard.php';
                }, 1500);
            }
        } catch (error) {
            console.error('Error:', error);
            showError(loginMessage, 'An error occurred. Please try again.');
        }
    });

    // Registration form
    registerForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        registerMessage.style.display = 'none';
        
        const formData = new FormData(registerForm);
        formData.append('register', 'true');

        // Client-side validation
        const username = formData.get('username');
        const email = formData.get('email');
        const password = formData.get('password');
        
        if (!username || username.length < 3) {
            showError(registerMessage, 'Username must be 3-20 characters');
            return;
        }
        
        if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
            showError(registerMessage, 'Please enter a valid email');
            return;
        }
        
        if (!password || password.length < 8) {
            showError(registerMessage, 'Password must be at least 8 characters');
            return;
        }

        try {
            const response = await fetch(window.location.href, {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                },
                credentials: 'same-origin'
            });
            
            const data = await response.json();
            showMessage(registerMessage, data.message, data.status);
            
            if (data.status === 'success') {
                registerForm.reset();
                setTimeout(() => {
                    loginLink.click();
                }, 1500);
            }
        } catch (error) {
            console.error('Error:', error);
            showError(registerMessage, 'An error occurred. Please try again.');
        }
    });
}

// Helper functions
function showMessage(element, message, type) {
    element.textContent = message;
    element.className = `message ${type}`;
    element.style.display = 'block';
    
    if (type === 'success') {
        element.style.backgroundColor = 'rgba(0, 255, 0, 0.1)';
        element.style.color = '#00cc00';
    } else {
        element.style.backgroundColor = 'rgba(255, 0, 0, 0.1)';
        element.style.color = '#ff3333';
    }
}

function showError(element, message) {
    showMessage(element, message, 'error');
}

// Initialize
document.addEventListener('DOMContentLoaded', () => {
    setupInputValidation();
    setupFormHandlers();
});