/**
 * AJAX поиск пользователей
 */
(function() {
    let searchTimeout = null;
    
    function searchUsers(query) {
        if (query.length < 2) {
            document.getElementById('user-list').innerHTML = '';
            return;
        }
        
        fetch('/api/user-search.php?q=' + encodeURIComponent(query))
            .then(response => response.json())
            .then(data => {
                const userList = document.getElementById('user-list');
                if (data.error) {
                    userList.innerHTML = '<p class="text-muted">Ошибка: ' + data.error + '</p>';
                    return;
                }
                
                if (data.users && data.users.length > 0) {
                    let html = '';
                    data.users.forEach(user => {
                        html += `
                            <div class="user-item">
                                <input type="radio" name="buyer_id" id="user_${user.id}" value="${user.id}" required>
                                <label for="user_${user.id}">
                                    <span><strong>${user.full_name}</strong> (${user.email})</span>
                                    <br>
                                    <small class="text-muted">Тип: ${user.user_type === 'legal' ? 'Юридическое лицо' : 'Физическое лицо'}</small>
                                </label>
                            </div>
                        `;
                    });
                    userList.innerHTML = html;
                } else {
                    userList.innerHTML = '<p class="text-muted">Пользователи не найдены</p>';
                }
            })
            .catch(error => {
                console.error('Ошибка поиска:', error);
                document.getElementById('user-list').innerHTML = '<p class="text-muted">Ошибка при поиске пользователей</p>';
            });
    }
    
    document.addEventListener('DOMContentLoaded', function() {
        const searchInput = document.getElementById('user-search');
        const userList = document.getElementById('user-list');
        
        if (searchInput && userList) {
            searchInput.addEventListener('input', function() {
                const query = this.value.trim();
                
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(function() {
                    searchUsers(query);
                }, 300); // Задержка 300мс для уменьшения количества запросов
            });
        }
    });
})();








