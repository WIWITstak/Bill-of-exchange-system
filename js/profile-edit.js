/**
 * Скрипт для улучшенного редактирования профиля с AJAX
 */

document.addEventListener('DOMContentLoaded', function() {
    // Инициализация табов
    initTabs();
    
    // Инициализация форм с AJAX
    initAjaxForms();
    
    // Валидация в реальном времени
    initRealTimeValidation();
    
    // Инициализация загрузки аватара
});

/**
 * Инициализация табов
 */
function initTabs() {
    const tabButtons = document.querySelectorAll('.profile-tab-btn');
    const tabContents = document.querySelectorAll('.profile-tab-content');
    
    tabButtons.forEach(button => {
        button.addEventListener('click', function() {
            const targetTab = this.dataset.tab;
            
            // Убираем активность у всех табов
            tabButtons.forEach(btn => btn.classList.remove('active'));
            tabContents.forEach(content => content.classList.remove('active'));
            
            // Активируем выбранный таб
            this.classList.add('active');
            document.getElementById(`tab-${targetTab}`).classList.add('active');
        });
    });
}

/**
 * Инициализация AJAX форм
 */
function initAjaxForms() {
    const forms = document.querySelectorAll('.profile-edit-form');
    
    forms.forEach(form => {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            const action = formData.get('action');
            const submitButton = this.querySelector('button[type="submit"]');
            const originalText = submitButton ? submitButton.innerHTML : '';
            
            // Показываем индикатор загрузки
            if (submitButton) {
                submitButton.disabled = true;
                submitButton.innerHTML = '<span class="spinner"></span> Сохранение...';
            }
            
            // Отправляем AJAX запрос
            fetch('/api/profile_edit.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (submitButton) {
                    submitButton.disabled = false;
                    submitButton.innerHTML = originalText;
                }
                
                if (data.success) {
                    showMessage(data.message || 'Изменения успешно сохранены!', 'success');
                    
                    // Обновляем данные на странице при необходимости
                    if (action === 'update_profile' && data.full_name) {
                        const fullNameInput = document.getElementById('full_name');
                        if (fullNameInput) {
                            fullNameInput.value = data.full_name;
                        }
                    }
                    
                    if (action === 'change_email' && data.email) {
                        const emailInput = document.getElementById('new_email');
                        const confirmEmailInput = document.getElementById('confirm_email');
                        if (emailInput) emailInput.value = '';
                        if (confirmEmailInput) confirmEmailInput.value = '';
                        
                        // Обновляем отображаемый email
                        const emailDisplay = document.getElementById('current_email_display');
                        if (emailDisplay) {
                            emailDisplay.value = data.email;
                        }
                    }
                    
                    if (action === 'change_password') {
                        // Очищаем поля пароля
                        const passwordInputs = this.querySelectorAll('input[type="password"]');
                        passwordInputs.forEach(input => input.value = '');
                    }
                    
                    if (action === 'update_company' && data.company) {
                        // Обновляем поля компании
                        if (data.company.address) {
                            const addressInput = document.getElementById('address');
                            if (addressInput) addressInput.value = data.company.address;
                        }
                        if (data.company.okved_code) {
                            const okvedInput = document.getElementById('okved_code');
                            if (okvedInput) okvedInput.value = data.company.okved_code;
                        }
                        if (data.company.employee_count !== undefined) {
                            const employeeInput = document.getElementById('employee_count');
                            if (employeeInput) employeeInput.value = data.company.employee_count;
                        }
                    }
                } else {
                    showMessage(data.message || 'Ошибка при сохранении изменений', 'error');
                }
            })
            .catch(error => {
                if (submitButton) {
                    submitButton.disabled = false;
                    submitButton.innerHTML = originalText;
                }
                console.error('Ошибка:', error);
                showMessage('Произошла ошибка при отправке данных', 'error');
            });
        });
    });
}

/**
 * Валидация в реальном времени
 */
function initRealTimeValidation() {
    // Валидация email
    const emailInputs = document.querySelectorAll('input[type="email"]');
    emailInputs.forEach(input => {
        input.addEventListener('blur', function() {
            validateEmail(this);
        });
        
        input.addEventListener('input', function() {
            clearFieldError(this);
        });
    });
    
    // Валидация пароля
    const passwordInputs = document.querySelectorAll('input[type="password"]');
    passwordInputs.forEach(input => {
        if (input.name === 'new_password' || input.name === 'confirm_password') {
            input.addEventListener('input', function() {
                validatePassword(this);
                
                // Проверка совпадения паролей
                if (input.name === 'new_password') {
                    const confirmPassword = document.getElementById('confirm_password');
                    if (confirmPassword && confirmPassword.value) {
                        validatePasswordMatch(document.getElementById('new_password'), confirmPassword);
                    }
                }
                
                if (input.name === 'confirm_password') {
                    const newPassword = document.getElementById('new_password');
                    if (newPassword) {
                        validatePasswordMatch(newPassword, this);
                    }
                }
            });
        }
    });
    
    // Валидация ФИО/названия
    const fullNameInput = document.getElementById('full_name');
    if (fullNameInput) {
        fullNameInput.addEventListener('blur', function() {
            if (this.value.trim().length < 2) {
                showFieldError(this, 'Минимум 2 символа');
            } else {
                clearFieldError(this);
            }
        });
    }
    
    // Валидация ОКВЭД
    const okvedInput = document.getElementById('okved_code');
    if (okvedInput) {
        okvedInput.addEventListener('blur', function() {
            const value = this.value.trim();
            if (value && !/^\d{2}\.\d{2}(\.\d{2})?$/.test(value)) {
                showFieldError(this, 'Неверный формат ОКВЭД (например: 62.01)');
            } else {
                clearFieldError(this);
            }
        });
    }
}

/**
 * Валидация email
 */
function validateEmail(input) {
    const email = input.value.trim();
    if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
        showFieldError(input, 'Неверный формат email');
        return false;
    } else {
        clearFieldError(input);
        return true;
    }
}

/**
 * Валидация пароля
 */
function validatePassword(input) {
    const password = input.value;
    if (password.length > 0 && password.length < 6) {
        showFieldError(input, 'Минимум 6 символов');
        return false;
    } else {
        clearFieldError(input);
        return true;
    }
}

/**
 * Проверка совпадения паролей
 */
function validatePasswordMatch(passwordInput, confirmInput) {
    if (confirmInput.value && passwordInput.value !== confirmInput.value) {
        showFieldError(confirmInput, 'Пароли не совпадают');
        return false;
    } else {
        clearFieldError(confirmInput);
        return true;
    }
}

/**
 * Показать ошибку поля
 */
function showFieldError(input, message) {
    clearFieldError(input);
    input.classList.add('error');
    
    const errorDiv = document.createElement('div');
    errorDiv.className = 'field-error';
    errorDiv.textContent = message;
    input.parentNode.appendChild(errorDiv);
}

/**
 * Очистить ошибку поля
 */
function clearFieldError(input) {
    input.classList.remove('error');
    const errorDiv = input.parentNode.querySelector('.field-error');
    if (errorDiv) {
        errorDiv.remove();
    }
}

/**
 * Показать сообщение
 */
function showMessage(message, type) {
    // Удаляем существующие сообщения
    const existingAlerts = document.querySelectorAll('.profile-alert');
    existingAlerts.forEach(alert => alert.remove());
    
    const alertDiv = document.createElement('div');
    alertDiv.className = `profile-alert alert-${type}`;
    alertDiv.textContent = message;
    
    // Вставляем сообщение после заголовка
    const header = document.querySelector('.dashboard-header');
    if (header && header.nextSibling) {
        header.parentNode.insertBefore(alertDiv, header.nextSibling);
    } else if (header) {
        header.parentNode.appendChild(alertDiv);
    }
    
    // Автоматическое скрытие через 5 секунд
    setTimeout(() => {
        alertDiv.style.opacity = '0';
        alertDiv.style.transition = 'opacity 0.3s';
        setTimeout(() => alertDiv.remove(), 300);
    }, 5000);
}

// Инициализация загрузки аватара
function initAvatarUpload() {
    const avatarUpload = document.getElementById('avatar-upload');
    let avatarPreview = document.getElementById('avatar-preview');
    const removeAvatarBtn = document.getElementById('remove-avatar-btn');
    
    if (!avatarUpload || !avatarPreview) return;
    
    // Обработка выбора файла
    avatarUpload.addEventListener('change', function(e) {
        const file = e.target.files[0];
        if (!file) return;
        
        // Проверка типа файла
        const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
        if (!allowedTypes.includes(file.type)) {
            showMessage('Разрешены только изображения: JPG, PNG, GIF, WEBP', 'error');
            return;
        }
        
        // Проверка размера (5MB)
        if (file.size > 5 * 1024 * 1024) {
            showMessage('Размер файла не должен превышать 5MB', 'error');
            return;
        }
        
        // Показываем превью
        const reader = new FileReader();
        reader.onload = function(e) {
            if (avatarPreview && (avatarPreview.tagName === 'DIV' || avatarPreview.tagName === 'div')) {
                const img = document.createElement('img');
                img.id = 'avatar-preview';
                img.src = e.target.result;
                img.alt = 'Фото профиля';
                img.className = 'avatar-preview-image';
                img.title = avatarPreview.title || '';
                img.style.cssText = 'width: 120px; height: 120px; object-fit: cover; border-radius: 50%; display: block;';
                if (avatarPreview.parentNode) {
                    avatarPreview.parentNode.replaceChild(img, avatarPreview);
                    avatarPreview = img;
                }
            } else if (avatarPreview && (avatarPreview.tagName === 'IMG' || avatarPreview.tagName === 'img')) {
                avatarPreview.src = e.target.result;
            }
        };
        reader.readAsDataURL(file);
        
        // Загружаем файл
        const formData = new FormData();
        formData.append('action', 'upload_avatar');
        formData.append('avatar', file);
        
        // Добавляем CSRF токен
        const csrfToken = window.csrfToken || '';
        if (csrfToken) {
            formData.append('_csrf_token', csrfToken);
        }
        
        const uploadBtn = document.querySelector('.btn-upload-avatar');
        const originalText = uploadBtn ? uploadBtn.innerHTML : '';
        if (uploadBtn) {
            uploadBtn.disabled = true;
            uploadBtn.innerHTML = '<span class="spinner"></span> Загрузка...';
        }
        
        fetch('/api/profile_edit.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (uploadBtn) {
                uploadBtn.disabled = false;
                uploadBtn.innerHTML = originalText;
            }
            
            if (data.success) {
                showMessage(data.message || 'Фото профиля успешно загружено!', 'success');
                
                // Обновляем превью
                if (data.avatar_url && avatarPreview) {
                    const avatarUrl = data.avatar_url + (data.avatar_url.includes('?') ? '&' : '?') + 't=' + Date.now();
                    
                    if (avatarPreview.tagName === 'DIV' || avatarPreview.tagName === 'div') {
                        const img = document.createElement('img');
                        img.id = 'avatar-preview';
                        img.src = avatarUrl;
                        img.alt = 'Фото профиля';
                        img.className = 'avatar-preview-image';
                        img.title = document.getElementById('full_name')?.value || '';
                        img.style.cssText = 'width: 120px; height: 120px; object-fit: cover; border-radius: 50%; display: block;';
                        img.onerror = function() {
                            console.error('Ошибка загрузки аватара:', avatarUrl);
                        };
                        img.onload = function() {
                            console.log('Аватар загружен:', avatarUrl);
                        };
                        if (avatarPreview.parentNode) {
                            avatarPreview.parentNode.replaceChild(img, avatarPreview);
                            avatarPreview = img;
                        }
                    } else if (avatarPreview.tagName === 'IMG' || avatarPreview.tagName === 'img') {
                        avatarPreview.onerror = function() {
                            console.error('Ошибка загрузки аватара:', avatarUrl);
                        };
                        avatarPreview.onload = function() {
                            console.log('Аватар загружен:', avatarUrl);
                        };
                        avatarPreview.src = avatarUrl;
                    }
                }
                
                // Показываем кнопку удаления
                if (removeAvatarBtn) {
                    removeAvatarBtn.style.display = '';
                }
                
                // Обновляем аватар в боковом меню
                updateSidebarAvatar(data.avatar_url, data.initials);
            } else {
                showMessage(data.message || 'Ошибка при загрузке фото', 'error');
                avatarUpload.value = '';
            }
        })
        .catch(error => {
            if (uploadBtn) {
                uploadBtn.disabled = false;
                uploadBtn.innerHTML = originalText;
            }
            showMessage('Произошла ошибка при загрузке файла', 'error');
            avatarUpload.value = '';
        });
    });
    
    // Обработка удаления аватара
    if (removeAvatarBtn) {
        removeAvatarBtn.addEventListener('click', function() {
            if (!confirm('Вы уверены, что хотите удалить фото профиля?')) {
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'remove_avatar');
            
            // Добавляем CSRF токен
            const csrfToken = window.csrfToken || '';
            if (csrfToken) {
                formData.append('_csrf_token', csrfToken);
            }
            
            const originalText = this.innerHTML;
            this.disabled = true;
            this.innerHTML = '<span class="spinner"></span> Удаление...';
            
            fetch('/api/profile_edit.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                this.disabled = false;
                this.innerHTML = originalText;
                
                if (data.success) {
                    showMessage(data.message || 'Фото профиля удалено', 'success');
                    
                    // Возвращаем превью к инициалам
                    const placeholder = document.createElement('div');
                    placeholder.id = 'avatar-preview';
                    placeholder.className = 'avatar-preview-placeholder';
                    placeholder.textContent = data.initials || '?';
                    placeholder.title = document.getElementById('full_name')?.value || '';
                    
                    if (avatarPreview && avatarPreview.parentNode) {
                        avatarPreview.parentNode.replaceChild(placeholder, avatarPreview);
                        avatarPreview = placeholder;
                    }
                    
                    this.style.display = 'none';
                    updateSidebarAvatar(null, data.initials);
                } else {
                    showMessage(data.message || 'Ошибка при удалении фото', 'error');
                }
            })
            .catch(error => {
                this.disabled = false;
                this.innerHTML = originalText;
                showMessage('Произошла ошибка при удалении фото', 'error');
            });
        });
    }
}

/**
 * Обновить аватар в боковом меню
 */
function updateSidebarAvatar(avatarUrl, initials) {
    const sidebarAvatarLink = document.querySelector('.sidebar-user-avatar-link');
    if (!sidebarAvatarLink) {
        console.warn('sidebar-user-avatar-link not found');
        return;
    }
    
    // Ищем элемент аватара - может быть img или div
    const avatarElement = sidebarAvatarLink.querySelector('img, div.sidebar-user-avatar, .sidebar-user-avatar-image, .sidebar-user-avatar');
    if (!avatarElement) {
        console.warn('Avatar element not found in sidebar');
        return;
    }
    
    const userName = document.querySelector('.sidebar-user-name');
    const userNameText = userName ? userName.textContent.trim() : '';
    
    if (avatarUrl) {
        const imgUrl = avatarUrl + (avatarUrl.includes('?') ? '&' : '?') + 't=' + Date.now();
        if (avatarElement.tagName === 'DIV' || avatarElement.tagName === 'div') {
            const img = document.createElement('img');
            img.src = imgUrl;
            img.alt = userNameText || 'Аватар';
            img.className = 'sidebar-user-avatar-image';
            img.title = userNameText;
            img.style.cssText = 'width: 50px; height: 50px; object-fit: cover; border-radius: 50%; display: block; border: 2px solid rgba(255,255,255,0.3);';
            img.onerror = function() {
                console.error('Ошибка загрузки аватара в меню:', imgUrl);
                // Возвращаем placeholder при ошибке
                const div = document.createElement('div');
                div.className = 'sidebar-user-avatar';
                div.textContent = initials || '?';
                div.title = userNameText;
                sidebarAvatarLink.replaceChild(div, img);
            };
            img.onload = function() {
                console.log('Аватар в меню загружен:', imgUrl);
            };
            if (sidebarAvatarLink && avatarElement.parentNode === sidebarAvatarLink) {
                sidebarAvatarLink.replaceChild(img, avatarElement);
            }
        } else if (avatarElement.tagName === 'IMG' || avatarElement.tagName === 'img') {
            avatarElement.onerror = function() {
                console.error('Ошибка загрузки аватара в меню:', imgUrl);
                // Возвращаем placeholder при ошибке
                const div = document.createElement('div');
                div.className = 'sidebar-user-avatar';
                div.textContent = initials || '?';
                div.title = userNameText;
                if (avatarElement.parentNode) {
                    avatarElement.parentNode.replaceChild(div, avatarElement);
                }
            };
            avatarElement.onload = function() {
                console.log('Аватар в меню загружен:', imgUrl);
            };
            avatarElement.src = imgUrl;
        }
    } else {
        const div = document.createElement('div');
        div.className = 'sidebar-user-avatar';
        div.textContent = initials || '?';
        div.title = userNameText;
        if (sidebarAvatarLink && avatarElement.parentNode === sidebarAvatarLink) {
            sidebarAvatarLink.replaceChild(div, avatarElement);
        }
    }
}

// Инициализация при загрузке страницы
document.addEventListener('DOMContentLoaded', function() {
    initAvatarUpload();
});


