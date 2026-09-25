/**
 * JavaScript для защиты от ботов
 * Устанавливает флаг выполнения JavaScript и отправляет токен
 */

(function() {
    'use strict';
    
    // Устанавливаем флаг выполнения JavaScript
    if (typeof fetch !== 'undefined') {
        // Отправляем запрос на установку флага JS
        fetch('/api/bot-protection.php?action=set_js_enabled', {
            method: 'GET',
            credentials: 'same-origin',
            cache: 'no-cache'
        }).catch(function(error) {
            // Игнорируем ошибки (если API недоступен)
            console.debug('Bot protection API not available');
        });
    }
    
    // Устанавливаем флаг в localStorage для быстрой проверки
    try {
        localStorage.setItem('_js_enabled', '1');
        localStorage.setItem('_js_enabled_time', Date.now().toString());
    } catch (e) {
        // Игнорируем ошибки localStorage
    }
    
    // Скрываем honeypot поля (если они есть)
    document.addEventListener('DOMContentLoaded', function() {
        var honeypotFields = document.querySelectorAll('[data-honeypot="true"]');
        honeypotFields.forEach(function(field) {
            if (field.style) {
                field.style.position = 'absolute';
                field.style.left = '-9999px';
                field.style.opacity = '0';
                field.style.pointerEvents = 'none';
            }
        });
    });
    
    // Добавляем токен JavaScript в формы
    document.addEventListener('DOMContentLoaded', function() {
        var forms = document.querySelectorAll('form[method="post"]');
        forms.forEach(function(form) {
            // Проверяем, есть ли уже поле с токеном
            if (!form.querySelector('input[name="_js_token"]')) {
                var tokenInput = document.createElement('input');
                tokenInput.type = 'hidden';
                tokenInput.name = '_js_token';
                tokenInput.value = getJsToken();
                form.appendChild(tokenInput);
            }
        });
    });
    
    /**
     * Получить JavaScript токен
     */
    function getJsToken() {
        // Пытаемся получить токен из скрытого поля или генерируем новый
        var tokenField = document.querySelector('input[name="_js_token_value"]');
        if (tokenField) {
            return tokenField.value;
        }
        
        // Генерируем простой токен на основе времени
        return btoa(Date.now().toString()).substring(0, 16);
    }
})();













