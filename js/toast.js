/**
 * Система Toast-уведомлений
 */

class Toast {
    static container = null;
    
    static init() {
        if (!this.container) {
            this.container = document.createElement('div');
            this.container.id = 'toast-container';
            this.container.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                z-index: 10000;
                display: flex;
                flex-direction: column;
                gap: 10px;
                pointer-events: none;
            `;
            document.body.appendChild(this.container);
        }
    }
    
    static show(message, type = 'info', duration = 5000) {
        this.init();
        
        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        toast.style.cssText = `
            background: ${this.getBackgroundColor(type)};
            color: ${this.getTextColor(type)};
            padding: 15px 20px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            min-width: 300px;
            max-width: 500px;
            pointer-events: auto;
            animation: slideIn 0.3s ease-out;
            border-left: 4px solid ${this.getBorderColor(type)};
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
        `;
        
        const messageEl = document.createElement('span');
        messageEl.textContent = message;
        messageEl.style.flex = '1';
        
        const closeBtn = document.createElement('button');
        closeBtn.innerHTML = '&times;';
        closeBtn.style.cssText = `
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: inherit;
            opacity: 0.7;
            padding: 0;
            width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
        `;
        closeBtn.onclick = () => this.hide(toast);
        
        toast.appendChild(messageEl);
        toast.appendChild(closeBtn);
        this.container.appendChild(toast);
        
        // Автоматическое скрытие
        if (duration > 0) {
            setTimeout(() => this.hide(toast), duration);
        }
        
        return toast;
    }
    
    static hide(toast) {
        if (toast && toast.parentNode) {
            toast.style.animation = 'slideOut 0.3s ease-out';
            setTimeout(() => {
                if (toast.parentNode) {
                    toast.parentNode.removeChild(toast);
                }
            }, 300);
        }
    }
    
    static success(message, duration = 5000) {
        return this.show(message, 'success', duration);
    }
    
    static error(message, duration = 7000) {
        return this.show(message, 'error', duration);
    }
    
    static info(message, duration = 5000) {
        return this.show(message, 'info', duration);
    }
    
    static warning(message, duration = 6000) {
        return this.show(message, 'warning', duration);
    }
    
    static getBackgroundColor(type) {
        const colors = {
            success: '#d4edda',
            error: '#f8d7da',
            info: '#d1ecf1',
            warning: '#fff3cd'
        };
        return colors[type] || colors.info;
    }
    
    static getTextColor(type) {
        const colors = {
            success: '#155724',
            error: '#721c24',
            info: '#0c5460',
            warning: '#856404'
        };
        return colors[type] || colors.info;
    }
    
    static getBorderColor(type) {
        const colors = {
            success: '#28a745',
            error: '#dc3545',
            info: '#17a2b8',
            warning: '#ffc107'
        };
        return colors[type] || colors.info;
    }
}

// Добавляем CSS анимации
const style = document.createElement('style');
style.textContent = `
    @keyframes slideIn {
        from {
            transform: translateX(100%);
            opacity: 0;
        }
        to {
            transform: translateX(0);
            opacity: 1;
        }
    }
    
    @keyframes slideOut {
        from {
            transform: translateX(0);
            opacity: 1;
        }
        to {
            transform: translateX(100%);
            opacity: 0;
        }
    }
`;
document.head.appendChild(style);








