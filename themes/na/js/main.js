document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.edit-post').forEach(function(button) {
        button.addEventListener('click', function() {
            var postId = this.dataset.postId;
			
            // Отправляем AJAX запрос, чтобы получить полный контент записи
            var xhr = new XMLHttpRequest();
            xhr.open('POST', MyScriptData.ajaxUrl, true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.onload = function() {
                if (xhr.status === 200) {
                    var response = JSON.parse(xhr.responseText);
                    if (response.success) {
                        var postContent = response.data.content;
                        document.querySelector('#post-form').action = MyScriptData.editPostUrl;
                        document.querySelector('#post-form input[name="post_id"]').value = postId;
                        document.querySelector('#post-form textarea[name="post_content"]').value = postContent;
                        document.querySelector('#post-form input[type="submit"]').value = 'Изменить';
						
                        // Ставим курсор в поле для редактирования
                        document.querySelector('#post-form textarea[name="post_content"]').focus();
						} else {
                        alert('Ошибка при загрузке содержимого записи.');
					}
				}
			};
            xhr.send('action=get_post_content&post_id=' + postId);
		});
	});
});


document.addEventListener('DOMContentLoaded', function() {
    var menuToggle = document.querySelector('.menu-toggle');
    var menu = document.querySelector('#site-navigation .menu');
	
    // Функция для открытия и закрытия меню
    function toggleMenu() {
        menu.classList.toggle('show');
        var isMenuOpen = menu.classList.contains('show');
        menuToggle.setAttribute('aria-expanded', isMenuOpen); // Обновляем атрибут aria-expanded
        menuToggle.setAttribute('aria-label', isMenuOpen ? 'Закрыть меню' : 'Открыть меню'); // Обновляем aria-label
	}
	
    // Если кнопка меню и меню найдены
    if (menuToggle && menu) {
        // При клике на кнопку открываем/закрываем меню
        menuToggle.addEventListener('click', toggleMenu);
		
        // Закрытие меню при клике вне его
        document.addEventListener('click', function(event) {
            if (!menu.contains(event.target) && !menuToggle.contains(event.target)) {
                menu.classList.remove('show');
                menuToggle.setAttribute('aria-expanded', 'false'); // Обновляем aria-expanded
                menuToggle.setAttribute('aria-label', 'Открыть меню'); // Обновляем aria-label
			}
		});
	}
});

document.addEventListener("DOMContentLoaded", function() {
    const title = document.querySelector(".colored-title");
    const text = title.textContent;
    title.innerHTML = '';
    for (let i = 0; i < text.length; i++) {
        const span = document.createElement('span');
        span.textContent = text[i];
        title.appendChild(span);
	}
});

document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.delete-post').forEach(function(button) {
        button.addEventListener('click', function() {
            var postId = this.getAttribute('data-post-id');
            if (confirm('Вы уверены, что хотите удалить эту запись?')) {
                // Создаем и отправляем POST-запрос для удаления записи
                var xhr = new XMLHttpRequest();
                xhr.open('POST', MyScriptData.ajaxUrl, true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.onload = function() {
                    if (xhr.status === 200) {
                        var response = JSON.parse(xhr.responseText);
                        if (response.success) {
                            document.querySelector('li[data-post-id="' + postId + '"]').remove();
                            alert('Запись успешно удалена.');
							} else {
                            console.error(response.message);
                            alert('Ошибка при удалении записи: ' + response.message);
						}
						} else {
                        console.error('Ошибка сети или сервера: ' + xhr.status);
					}
				};
                xhr.onerror = function() {
                    console.error('Ошибка запроса.');
				};
                xhr.send('action=delete_post&post_id=' + postId);
			}
		});
	});
});

document.addEventListener('DOMContentLoaded', function() {
    var page = 1;
    var loading = false;
    var loadMoreButton = document.getElementById('load-more-posts');
	
    if (loadMoreButton) {
        loadMoreButton.addEventListener('click', function() {
            if (!loading) {
                loading = true;
                page++;
                var xhr = new XMLHttpRequest();
                xhr.open('POST', myAjax.ajaxurl, true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
                xhr.onload = function() {
                    if (xhr.status === 200) {
                        var response = xhr.responseText;
                        if (response) {
                            var postsList = document.getElementById('posts-list');
                            postsList.insertAdjacentHTML('beforeend', response);
							} else {
                            loadMoreButton.style.display = 'none';
						}
                        loading = false;
					}
				};
                xhr.send('action=load_more_posts&page=' + page);
			}
		});
	}
});

document.addEventListener('DOMContentLoaded', function() {
    var donateWidget = document.querySelector('.donate-widget');
	
    window.addEventListener('scroll', function() {
        if (window.scrollY > 100) {
            donateWidget.style.display = 'block';
			} else {
            donateWidget.style.display = 'none';
		}
	});
});

jQuery(document).ready(function($) {
    $('#toggle-recommendations').on('click', function() {
        var $button = $(this);
        var $description = $('.archive-description');
        
        $description.slideToggle(400, function() {
            if ($description.is(':visible')) {
                $button.html('<i class="fa fa-arrow-up"></i> Скрыть Описание');
				} else {
                $button.html('<i class="fa fa-arrow-down"></i> Описание');
			}
		});
	});
});

document.addEventListener('DOMContentLoaded', function() {
    const increaseFontButton = document.getElementById('increase-font');
    const paragraphs_p = document.querySelectorAll('.child-category p');
    const paragraphs = document.querySelectorAll('.child-category');
    const paragraphsDescription = document.querySelector('.child-category');
    const paragraphsArchive_p = document.querySelectorAll('.archive-description p');
    const paragraphsArchive = document.querySelectorAll('.archive-description');
    const archiveDescription = document.querySelector('.archive-description');
	
    increaseFontButton.addEventListener('click', function() {
        // Увеличиваем размер шрифта
        paragraphs.forEach(paragraph => {
            const currentFontSize = window.getComputedStyle(paragraph).fontSize;
            const newSize = parseFloat(currentFontSize) + 4;
            paragraph.style.fontSize = `${newSize}px`;
		});
        paragraphs_p.forEach(paragraph => {
            const currentFontSize = window.getComputedStyle(paragraph).fontSize;
            const newSize = parseFloat(currentFontSize) + 4;
            paragraph.style.fontSize = `${newSize}px`;
		});
		
        paragraphsArchive.forEach(paragraph => {
            const currentFontSize = window.getComputedStyle(paragraph).fontSize;
            const newSize = parseFloat(currentFontSize) + 4;
            paragraph.style.fontSize = `${newSize}px`;
		});
		
        paragraphsArchive_p.forEach(paragraph => {
            const currentFontSize = window.getComputedStyle(paragraph).fontSize;
            const newSize = parseFloat(currentFontSize) + 4;
            paragraph.style.fontSize = `${newSize}px`;
		});
		
        // Расширяем блок до ширины экрана
        if (archiveDescription) {
            archiveDescription.classList.add('expanded');
            paragraphsDescription.classList.add('expanded');
		}
	});
});

document.getElementById('telegramForm').addEventListener('submit', function(event) {
    event.preventDefault();

    const name = document.getElementById('name').value;
    const text = document.getElementById('text').value;

    const formData = new FormData();
    formData.append('action', 'send_to_telegram'); // AJAX action
    formData.append('name', name);
    formData.append('text', text);

    fetch(telegram_form_ajax.ajax_url, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            document.getElementById('message').innerText = 'Сообщение отправлено!';
        } else {
            document.getElementById('message').innerText = data.data || 'Ошибка отправки.';
        }
    })
    .catch(error => {
        document.getElementById('message').innerText = 'Ошибка отправки.';
        console.error('Ошибка:', error);
    });
});


