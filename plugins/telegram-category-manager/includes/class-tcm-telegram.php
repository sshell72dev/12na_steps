<?php
/**
 * Класс для работы с Telegram API - получение сообщений и создание записей
 */

if (!defined('ABSPATH')) {
    exit;
}

class TCM_Telegram {
    
    private $users;
    private $last_deepseek_error = null;
    
    public function __construct() {
        $this->users = new TCM_Users();
    }
    
    /**
     * Обработка входящего webhook от Telegram
     * 
     * @param array $update Данные обновления от Telegram
     * @return bool|WP_Error
     */
    public function handle_webhook($update) {
        $log_enabled = defined('WP_DEBUG') && WP_DEBUG;
        
        if ($log_enabled) {
            error_log('TCM: handle_webhook called. Update keys: ' . implode(', ', array_keys($update)));
        }
        
        // Обработка callback_query (нажатие на кнопки)
        if (isset($update['callback_query'])) {
            return $this->handle_callback_query($update['callback_query']);
        }
        
        if (!isset($update['message'])) {
            if ($log_enabled) {
                error_log('TCM: No message in update. Update structure: ' . print_r($update, true));
            }
            return false;
        }
        
        $message = $update['message'];
        $chat_id = isset($message['chat']['id']) ? (string)$message['chat']['id'] : '';
        $text = isset($message['text']) ? trim($message['text']) : '';
        $message_id = isset($message['message_id']) ? $message['message_id'] : '';
        $from = isset($message['from']) ? $message['from'] : array();
        $user_id_telegram = isset($from['id']) ? (string)$from['id'] : '';
        
        if ($log_enabled) {
            error_log('TCM: Processing message. Chat ID: ' . $chat_id . ', Text: ' . $text . ', User ID: ' . $user_id_telegram);
            error_log('TCM: Message structure: ' . print_r($message, true));
        }
        
        if (empty($chat_id)) {
            if ($log_enabled) {
                error_log('TCM: Empty chat_id');
            }
            // Отправляем сообщение об ошибке, если возможно
            if (!empty($message['from']['id'])) {
                $this->send_reply((string)$message['from']['id'], 'Ошибка: не удалось определить чат. Обратитесь к администратору.');
            }
            return false;
        }
        
        // Обработка команд
        if (!empty($text) && strpos($text, '/') === 0) {
            if ($log_enabled) {
                error_log('TCM: Handling command: ' . $text);
            }
            $result = $this->handle_command($text, $chat_id, $user_id_telegram, $from);
            if ($log_enabled) {
                error_log('TCM: Command result: ' . print_r($result, true));
                if (is_wp_error($result)) {
                    error_log('TCM: Command error: ' . $result->get_error_message());
                }
            }
            return $result;
        }
        
        // Обработка нажатий кнопок Reply Keyboard (прилипающей клавиатуры)
        $reply_keyboard_actions = array(
            '📂 Выбор Шага',
            '📝 Мои записи',
            '⚙️ Настройки',
            '❓ Справка',
            '💬 Техподдержка',
            '🏠 Главное меню'
        );
        
        // Проверяем, является ли это кнопкой помощи ИИ
        // Это нужно проверить ДО обработки других reply keyboard actions
        if ($text === '🤖 Получить помощь ИИ по текущей точке' ||
            $text === '⭐ PRO 🤖 Получить помощь ИИ по текущей точке') {
            // Получаем текущую выбранную категорию
            $user = $this->users->get_user_by_telegram_id($user_id_telegram);
            if (!$user) {
                $this->show_registration_instruction($chat_id);
                return new WP_Error('tcm_user_not_registered', 'Пользователь не зарегистрирован');
            }
            
            $current_category_id = $this->get_category_for_chat($chat_id, $user_id_telegram);
            if ($current_category_id > 0) {
                $point_id = $this->get_category_at_level($current_category_id, 2);
                if ($point_id) {
                    return $this->handle_ai_help($chat_id, $point_id, $user_id_telegram);
                } else {
                    $this->send_reply_with_reply_keyboard($chat_id, 
                        '❌ Точка не выбрана. Пожалуйста, выберите точку через меню "📂 Выбор Шага".',
                        $this->get_main_reply_keyboard()
                    );
                    return false;
                }
            } else {
                $this->send_reply_with_reply_keyboard($chat_id, 
                    '❌ Точка не выбрана. Пожалуйста, выберите точку через меню "📂 Выбор Шага".',
                    $this->get_main_reply_keyboard()
                );
                return false;
            }
        }
        
        if (in_array($text, $reply_keyboard_actions)) {
            if ($log_enabled) {
                error_log('TCM: Handling Reply Keyboard action: ' . $text);
            }
            return $this->handle_reply_keyboard_action($text, $chat_id, $user_id_telegram);
        }
        
        // Если это не команда и нет текста, игнорируем
        if (empty($text)) {
            return false;
        }
        
        // Проверяем, зарегистрирован ли пользователь
        $user = $this->users->get_user_by_telegram_id($user_id_telegram);
        
        if (!$user) {
            // Проверяем, ожидает ли пользователь ввода имени для регистрации
            $waiting_name = get_option('tcm_waiting_name_' . $user_id_telegram, false);
            
            if ($waiting_name) {
                // Пользователь ожидает ввода имени - обрабатываем текст как имя
                if ($log_enabled) {
                    error_log('TCM: Processing name input for registration: ' . $text);
                }
                
                // Сохраняем имя во временное хранилище
                update_option('tcm_temp_name_' . $user_id_telegram, $text);
                
                // Удаляем состояние ожидания имени
                delete_option('tcm_waiting_name_' . $user_id_telegram);
                
                // Устанавливаем состояние ожидания выбора проблем
                update_option('tcm_waiting_problems_' . $user_id_telegram, true);
                
                // Показываем вопрос про проблемы с кнопками
                $this->show_problems_question($chat_id, $user_id_telegram);
                
                return true;
            }
            
            // Проверяем, ожидает ли пользователь выбора проблем
            $waiting_problems = get_option('tcm_waiting_problems_' . $user_id_telegram, false);
            if ($waiting_problems) {
                // Пользователь уже выбрал проблемы через кнопки, пропускаем
                return true;
            }
            
            // Показываем инструкцию для регистрации
            $this->show_registration_instruction($chat_id);
            
            return new WP_Error('tcm_user_not_registered', 'Пользователь не зарегистрирован');
        }
        
        // Сохраняем chat_id пользователя для напоминаний
        $wp_user_id = $this->get_wp_user_id($user_id_telegram);
        if ($wp_user_id) {
            $saved_chat_id = get_user_meta($wp_user_id, 'tcm_telegram_chat_id', true);
            if (!$saved_chat_id || $saved_chat_id != $chat_id) {
                update_user_meta($wp_user_id, 'tcm_telegram_chat_id', $chat_id);
            }
        }
        
        // Проверяем, ожидает ли пользователь отправки сообщения в поддержку
        $awaiting_support_message = get_user_meta($user->ID, 'tcm_awaiting_support_message', true);
        if ($awaiting_support_message) {
            // Пользователь ожидает отправки сообщения в поддержку - обрабатываем текст как сообщение
            if ($log_enabled) {
                error_log('TCM: Processing support message: ' . $text);
            }
            
            return $this->send_support_message($chat_id, $user_id_telegram, $text);
        }
        
        // Проверяем, редактирует ли пользователь запись
        $editing_post_id = get_user_meta($user->ID, 'tcm_editing_post_id', true);
        if ($editing_post_id) {
            // Пользователь редактирует запись - обрабатываем текст как новый контент
            if ($log_enabled) {
                error_log('TCM: Processing post edit. Post ID: ' . $editing_post_id . ', New content: ' . $text);
            }
            
            return $this->save_edited_post($chat_id, $user_id_telegram, $user->ID, $editing_post_id, $text);
        }
        
        // Проверяем, не является ли это ответом на вопрос анкеты
        $current_question = get_user_meta($user->ID, 'tcm_questionnaire_current_question', true);
        if (!empty($current_question) && is_array($current_question)) {
            // Это ответ на вопрос анкеты - обрабатываем и НЕ создаем запись
            $result = $this->process_questionnaire_answer_simple($chat_id, $user_id_telegram, $user->ID, $text, $current_question);
            if ($result) {
                // Ответ успешно обработан, не создаем запись
                return true;
            }
            // Если ответ не распознан, продолжаем как обычное сообщение
        }
        
        // Получаем категорию для этого чата (с учетом пользователя)
        $category_id = $this->get_category_for_chat($chat_id, $user_id_telegram);
        
        if (!$category_id) {
            // Если категория не настроена, отправляем сообщение с предложением выбрать
            $keyboard = array(
                array(
                    array('text' => '📂 Выбрать категорию', 'callback_data' => 'category:0'),
                    array('text' => '🏠 Главное меню', 'callback_data' => 'menu')
                )
            );
            
            $this->send_reply_with_keyboard($chat_id, 
                "❌ <b>Категория не выбрана</b>\n\n" .
                "Пожалуйста, выберите категорию для создания записей.\n\n" .
                "Используйте кнопку ниже или меню.",
                $keyboard
            );
            return new WP_Error('tcm_no_category', 'Категория не настроена для чата');
        }
        
        // Создаем запись в WordPress от имени пользователя
        $post_id = $this->create_post_from_message($text, $category_id, $chat_id, $message_id, $user->ID);
        
        if (is_wp_error($post_id)) {
            $this->send_reply($chat_id, 'Ошибка при создании записи: ' . $post_id->get_error_message());
            return $post_id;
        }
        
        // Получаем ссылку на запись
        $post_url = get_permalink($post_id);
        if (!$post_url) {
            $post_url = home_url('/?p=' . $post_id);
        }
        
        // Отправляем текст записи
        $this->send_reply_with_reply_keyboard($chat_id, $text, $this->get_main_reply_keyboard());
        
        // Получаем информацию о категории и количестве записей
        $current_category = get_category($category_id);
        $category_name = $current_category ? esc_html($current_category->name) : '';
        $posts_count = $this->get_category_posts_count($category_id, $user->ID);
        
        // Отправляем подтверждение со ссылкой (с прилипающей клавиатурой)
        $message = "✅ <b>Запись успешно создана!</b>";
        
        // Добавляем количество записей и название категории
        if ($category_name && $posts_count > 0) {
            $message .= " (" . $posts_count . ") " . $category_name;
        }
        
        $message .= "\n\n🔗 <a href=\"" . esc_url($post_url) . "\">Открыть запись на сайте</a>";
        
        $this->send_reply_with_reply_keyboard($chat_id, $message, $this->get_main_reply_keyboard());
        
        // Анкета больше не показывается после записи точки
        // Она будет показываться только после нажатия "Получить помощь ИИ"
        
        return true;
    }
    
    /**
     * Обработка команд от пользователя
     * 
     * @param string $text Текст команды
     * @param string $chat_id ID чата
     * @param string $user_id_telegram Telegram ID пользователя
     * @param array $from Данные пользователя из Telegram
     * @return bool|WP_Error
     */
    private function handle_command($text, $chat_id, $user_id_telegram, $from) {
        $log_enabled = defined('WP_DEBUG') && WP_DEBUG;
        
        // Убираем @botname если есть
        $text = preg_replace('/@\w+\s*/', '', $text);
        
        $parts = explode(' ', $text, 2);
        $command = strtolower(trim($parts[0]));
        $args = isset($parts[1]) ? trim($parts[1]) : '';
        
        if ($log_enabled) {
            error_log('TCM: Command parsed - command: ' . $command . ', args: ' . $args);
        }
        
        switch ($command) {
            case '/start':
                // Проверяем регистрацию
                $user = $this->users->get_user_by_telegram_id($user_id_telegram);
                if (!$user) {
                    // Пользователь не зарегистрирован - показываем короткое приветствие и запрашиваем имя
                    update_option('tcm_waiting_name_' . $user_id_telegram, true);
                    $this->send_reply($chat_id, 
                        "👋 <b>Добро пожаловать!</b>\n\n" .
                        "📝 Пожалуйста, введите ваше имя:"
                    );
                    return true;
                }
                // Пользователь зарегистрирован - показываем главное меню с прилипающей клавиатурой
                $result = $this->show_main_menu_with_reply_keyboard($chat_id);
                if ($log_enabled) {
                    error_log('TCM: /start result: ' . print_r($result, true));
                }
                return $result;
                
            case '/help':
                $result = $this->handle_help($chat_id);
                if ($log_enabled) {
                    error_log('TCM: /help result: ' . print_r($result, true));
                }
                return $result;
                
            case '/menu':
                $result = $this->show_main_menu($chat_id);
                if ($log_enabled) {
                    error_log('TCM: /menu result: ' . print_r($result, true));
                }
                return $result;
                
            case '/register':
                $result = $this->handle_register($chat_id, $user_id_telegram, $args, $from);
                if ($log_enabled) {
                    error_log('TCM: /register result: ' . print_r($result, true));
                }
                return $result;
                
            case '/link':
                $result = $this->handle_link($chat_id, $user_id_telegram, $args, $from);
                if ($log_enabled) {
                    error_log('TCM: /link result: ' . print_r($result, true));
                }
                return $result;
                
            case '/status':
                $result = $this->handle_status($chat_id, $user_id_telegram);
                if ($log_enabled) {
                    error_log('TCM: /status result: ' . print_r($result, true));
                }
                return $result;
                
            case '/cancel':
                $user = $this->users->get_user_by_telegram_id($user_id_telegram);
                if ($user) {
                    $wp_user_id = $this->get_wp_user_id($user_id_telegram);
                    $editing_post_id = get_user_meta($wp_user_id, 'tcm_editing_post_id', true);
                    if ($editing_post_id) {
                        $result = $this->handle_cancel_edit_post($chat_id, $user_id_telegram);
                        if ($log_enabled) {
                            error_log('TCM: /cancel (edit) result: ' . print_r($result, true));
                        }
                        return $result;
                    }
                }
                $this->send_reply($chat_id, 'Нет активного редактирования для отмены.');
                return true;
                
            default:
                if ($log_enabled) {
                    error_log('TCM: Unknown command: ' . $command);
                }
                $this->send_reply($chat_id, 
                    "Неизвестная команда.\n\n" .
                    "Используйте /menu для открытия меню или /help для справки."
                );
                return false;
        }
    }
    
    /**
     * Обработка команды /help
     */
    private function handle_help($chat_id) {
        $log_enabled = defined('WP_DEBUG') && WP_DEBUG;
        
        if ($log_enabled) {
            error_log('TCM: handle_help called for chat ' . $chat_id);
        }
        
        $help_text = 
            "🤖 <b>Telegram Category Manager</b>\n\n" .
            "Доступные команды:\n\n" .
            "/start или /help - показать эту справку\n" .
            "/register &lt;имя&gt; - создать новый аккаунт\n" .
            "   Пример: /register Иван Иванов\n\n" .
            "/link &lt;код&gt; - привязать Telegram к существующему аккаунту\n" .
            "   Код можно получить в админ-панели WordPress\n\n" .
            "/status - проверить статус регистрации\n\n" .
            "После регистрации просто отправляйте сообщения боту, и они будут создаваться как записи на сайте.";
        
        if ($log_enabled) {
            error_log('TCM: Sending help text to chat ' . $chat_id);
        }
        
        $result = $this->send_reply($chat_id, $help_text);
        
        if ($log_enabled) {
            if (is_wp_error($result)) {
                error_log('TCM: Error sending help: ' . $result->get_error_message());
            } else {
                error_log('TCM: Help sent successfully');
            }
        }
        
        return $result;
    }
    
    /**
     * Обработка команды /register
     */
    private function handle_register($chat_id, $user_id_telegram, $display_name, $from) {
        $log_enabled = defined('WP_DEBUG') && WP_DEBUG;
        
        if ($log_enabled) {
            error_log('TCM: /register called. Chat ID: ' . $chat_id . ', User ID: ' . $user_id_telegram . ', Name: ' . $display_name);
        }
        
        // Проверяем, что user_id_telegram не пустой
        if (empty($user_id_telegram)) {
            if ($log_enabled) {
                error_log('TCM: Empty user_id_telegram in /register');
            }
            $this->send_reply($chat_id, 
                "❌ Ошибка: не удалось определить ваш Telegram ID. Попробуйте еще раз."
            );
            return false;
        }
        
        // Проверяем, не зарегистрирован ли уже
        $existing_user = $this->users->get_user_by_telegram_id($user_id_telegram);
        if ($existing_user) {
            $this->send_reply($chat_id, 
                "✅ Вы уже зарегистрированы!\n\n" .
                "Имя: " . $existing_user->display_name . "\n" .
                "Username: @" . $existing_user->user_login . "\n\n" .
                "Отправляйте сообщения боту, чтобы создавать записи на сайте."
            );
            return true;
        }
        
        // Если имя не указано, запрашиваем его
        if (empty($display_name)) {
            // Сохраняем состояние ожидания имени
            update_option('tcm_waiting_name_' . $user_id_telegram, true);
            
            $this->send_reply($chat_id, 
                "👋 <b>Добро пожаловать!</b>\n\n" .
                "📝 Пожалуйста, введите ваше имя:"
            );
            return true;
        }
        
        // Имя указано - создаем пользователя
        if ($log_enabled) {
            error_log('TCM: Creating new user from Telegram');
        }
        
        $user = $this->users->create_user_from_telegram($user_id_telegram, $display_name, $from);
        
        if (is_wp_error($user)) {
            if ($log_enabled) {
                error_log('TCM: User creation error: ' . $user->get_error_message());
            }
            // Удаляем состояние ожидания
            delete_option('tcm_waiting_name_' . $user_id_telegram);
            $this->send_reply($chat_id, 
                "❌ Ошибка при регистрации: " . $user->get_error_message()
            );
            return $user;
        }
        
        if ($log_enabled) {
            error_log('TCM: User created successfully. ID: ' . $user->ID);
        }
        
        // Удаляем состояние ожидания
        delete_option('tcm_waiting_name_' . $user_id_telegram);
        
        // Сохраняем chat_id пользователя для напоминаний
        update_user_meta($user->ID, 'tcm_telegram_chat_id', $chat_id);
        
        // Показываем главное меню с прилипающей клавиатурой
        $this->show_main_menu_with_reply_keyboard($chat_id);
        
        // Приветственное сообщение с инструкцией
        $welcome_message = 
            "👋 <b>Добро пожаловать!</b>\n\n" .
            "✅ Регистрация успешна!\n\n" .
            "📋 <b>Ваши данные:</b>\n" .
            "• Имя: " . esc_html($user->display_name) . "\n" .
            "• Username: " . esc_html($user->user_login) . "\n\n" .
            "📖 <b>Краткая инструкция:</b>\n\n" .
            "1️⃣ <b>Выбор категории</b>\n" .
            "Нажмите кнопку «📂 Выбор Шага» в меню, чтобы выбрать категорию для ваших записей.\n\n" .
            "2️⃣ <b>Создание записей</b>\n" .
            "Просто отправляйте сообщения боту — они автоматически будут созданы как записи в выбранной категории.\n\n" .
            "3️⃣ <b>Просмотр записей</b>\n" .
            "Используйте кнопку «📝 Мои записи» для просмотра ваших записей по Шагам, Главам и Точкам.\n\n" .
            "4️⃣ <b>Настройки</b>\n" .
            "В разделе «⚙️ Настройки» вы можете изменить выбранную категорию и другие параметры.\n\n" .
            "💡 <b>Совет:</b> Начните с выбора Шага через меню, затем отправляйте свои записи боту.\n\n" .
            "Желаем успехов в работе! 🚀";
        
        $this->send_reply($chat_id, $welcome_message);
        
        return true;
    }
    
    /**
     * Показ вопроса про проблемы
     */
    private function show_problems_question($chat_id, $user_id_telegram) {
        $text = "📋 <b>Обозначьте свою проблему</b>\n\n";
        $text .= "Выберите одну или несколько проблем, которые вас беспокоят:";
        
        // Получаем уже выбранные проблемы
        $selected_problems = get_option('tcm_temp_problems_' . $user_id_telegram, array());
        if (!is_array($selected_problems)) {
            $selected_problems = array();
        }
        
        // Определяем проблемы
        $problems = array(
            'drugs' => 'Наркотики',
            'alcohol' => 'Алкоголь',
            'gambling' => 'Игромания',
            'depression' => 'Депрессия',
            'family_conflicts' => 'Конфликты в семье',
            'work_conflicts' => 'Конфликты на работе'
        );
        
        // Создаем кнопки (по 2 в ряд)
        $keyboard = array();
        $row = array();
        $button_count = 0;
        
        foreach ($problems as $key => $label) {
            $is_selected = in_array($key, $selected_problems);
            $button_text = ($is_selected ? '✅ ' : '') . $label;
            
            $row[] = array(
                'text' => $button_text,
                'callback_data' => 'registration:select_problem:' . $key
            );
            
            $button_count++;
            if ($button_count % 2 == 0) {
                $keyboard[] = $row;
                $row = array();
            }
        }
        
        // Добавляем последний ряд, если он не пустой
        if (!empty($row)) {
            $keyboard[] = $row;
        }
        
        // Кнопка "Готово" если выбрана хотя бы одна проблема
        if (!empty($selected_problems)) {
            $keyboard[] = array(
                array('text' => '✅ Готово', 'callback_data' => 'registration:finish_problems')
            );
        }
        
        $this->send_reply_with_keyboard($chat_id, $text, $keyboard);
    }
    
    /**
     * Обработка выбора проблемы
     */
    private function handle_problem_selection($chat_id, $problem_key, $user_id_telegram, $callback_id = '') {
        $log_enabled = defined('WP_DEBUG') && WP_DEBUG;
        
        // Получаем message_id из callback_query для обновления сообщения
        global $tcm_current_callback_query;
        $message_id = isset($tcm_current_callback_query) && isset($tcm_current_callback_query['message']['message_id']) ? $tcm_current_callback_query['message']['message_id'] : 0;
        
        // Получаем уже выбранные проблемы
        $selected_problems = get_option('tcm_temp_problems_' . $user_id_telegram, array());
        if (!is_array($selected_problems)) {
            $selected_problems = array();
        }
        
        // Переключаем выбор проблемы
        if (in_array($problem_key, $selected_problems)) {
            // Убираем из выбранных
            $selected_problems = array_values(array_diff($selected_problems, array($problem_key)));
        } else {
            // Добавляем к выбранным
            $selected_problems[] = $problem_key;
        }
        
        // Сохраняем обновленный список
        update_option('tcm_temp_problems_' . $user_id_telegram, $selected_problems);
        
        // Обновляем сообщение с новыми кнопками
        $text = "📋 <b>Обозначьте свою проблему</b>\n\n";
        $text .= "Выберите одну или несколько проблем, которые вас беспокоят:";
        
        // Определяем проблемы
        $problems = array(
            'drugs' => 'Наркотики',
            'alcohol' => 'Алкоголь',
            'gambling' => 'Игромания',
            'depression' => 'Депрессия',
            'family_conflicts' => 'Конфликты в семье',
            'work_conflicts' => 'Конфликты на работе'
        );
        
        // Создаем кнопки (по 2 в ряд)
        $keyboard = array();
        $row = array();
        $button_count = 0;
        
        foreach ($problems as $key => $label) {
            $is_selected = in_array($key, $selected_problems);
            $button_text = ($is_selected ? '✅ ' : '') . $label;
            
            $row[] = array(
                'text' => $button_text,
                'callback_data' => 'registration:select_problem:' . $key
            );
            
            $button_count++;
            if ($button_count % 2 == 0) {
                $keyboard[] = $row;
                $row = array();
            }
        }
        
        // Добавляем последний ряд, если он не пустой
        if (!empty($row)) {
            $keyboard[] = $row;
        }
        
        // Кнопка "Готово" если выбрана хотя бы одна проблема
        if (!empty($selected_problems)) {
            $keyboard[] = array(
                array('text' => '✅ Готово', 'callback_data' => 'registration:finish_problems')
            );
        }
        
        // Обновляем сообщение, если есть message_id
        if ($message_id > 0) {
            $this->edit_message_with_keyboard($chat_id, $message_id, $text, $keyboard);
        } else {
            // Если нет message_id, отправляем новое сообщение
            $this->send_reply_with_keyboard($chat_id, $text, $keyboard);
        }
        
        return true;
    }
    
    /**
     * Обновление сообщения с inline клавиатурой
     */
    private function edit_message_with_keyboard($chat_id, $message_id, $text, $keyboard) {
        $log_enabled = defined('WP_DEBUG') && WP_DEBUG;
        $token = get_option('tcm_telegram_token', '');
        
        if (empty($token)) {
            return false;
        }
        
        $url = "https://api.telegram.org/bot{$token}/editMessageText";
        
        $reply_markup = json_encode(array(
            'inline_keyboard' => $keyboard
        ));
        
        $body = array(
            'chat_id' => $chat_id,
            'message_id' => $message_id,
            'text' => $text,
            'parse_mode' => 'HTML',
            'reply_markup' => $reply_markup
        );
        
        $args = array(
            'body' => $body,
            'timeout' => 30
        );
        
        $response = wp_remote_post($url, $args);
        
        if ($log_enabled) {
            if (is_wp_error($response)) {
                error_log('TCM: edit_message_with_keyboard error: ' . $response->get_error_message());
            } else {
                error_log('TCM: edit_message_with_keyboard success');
            }
        }
        
        return $response;
    }
    
    /**
     * Завершение регистрации с проблемами
     */
    private function finish_registration_with_problems($chat_id, $user_id_telegram, $from = array()) {
        $log_enabled = defined('WP_DEBUG') && WP_DEBUG;
        
        // Получаем сохраненное имя
        $display_name = get_option('tcm_temp_name_' . $user_id_telegram, '');
        if (empty($display_name)) {
            $this->send_reply($chat_id, '❌ Ошибка: имя не найдено. Пожалуйста, начните заново с /start');
            return false;
        }
        
        // Получаем выбранные проблемы
        $selected_problems = get_option('tcm_temp_problems_' . $user_id_telegram, array());
        if (!is_array($selected_problems)) {
            $selected_problems = array();
        }
        
        // Создаем пользователя
        $user = $this->users->create_user_from_telegram($user_id_telegram, $display_name, $from);
        
        if (is_wp_error($user)) {
            if ($log_enabled) {
                error_log('TCM: User creation error: ' . $user->get_error_message());
            }
            $this->send_reply($chat_id, 
                "❌ Ошибка при регистрации: " . $user->get_error_message()
            );
            return $user;
        }
        
        if ($log_enabled) {
            error_log('TCM: User created successfully. ID: ' . $user->ID);
        }
        
        // Сохраняем выбранные проблемы в user_meta
        if (!empty($selected_problems)) {
            update_user_meta($user->ID, 'tcm_user_problems', $selected_problems);
        }
        
        // Очищаем временные данные
        delete_option('tcm_temp_name_' . $user_id_telegram);
        delete_option('tcm_temp_problems_' . $user_id_telegram);
        delete_option('tcm_waiting_problems_' . $user_id_telegram);
        
        // Сохраняем chat_id пользователя для напоминаний
        update_user_meta($user->ID, 'tcm_telegram_chat_id', $chat_id);
        
        // Показываем главное меню с прилипающей клавиатурой
        $this->show_main_menu_with_reply_keyboard($chat_id);
        
        // Короткое приветственное сообщение
        $welcome_message = 
            "👋 <b>Добро пожаловать, " . esc_html($display_name) . "!</b>\n\n" .
            "✅ Регистрация завершена.\n\n" .
            "Теперь вы можете выбирать категории и создавать записи.";
        
        $this->send_reply($chat_id, $welcome_message);
        
        return true;
    }
    
    /**
     * Обработка команды /link
     */
    private function handle_link($chat_id, $user_id_telegram, $verification_code, $from = array()) {
        if (empty($verification_code)) {
            $this->send_reply($chat_id, 
                "❌ Укажите код верификации.\n\n" .
                "Пример: /link ABC123\n\n" .
                "Код можно получить в админ-панели WordPress в разделе управления пользователями."
            );
            return false;
        }
        
        // Приводим код к верхнему регистру для поиска
        $verification_code = strtoupper(trim($verification_code));
        
        // Ищем пользователя по коду верификации (поиск нечувствителен к регистру через meta_query)
        $users = get_users(array(
            'meta_query' => array(
                array(
                    'key' => 'tcm_verification_code',
                    'value' => $verification_code,
                    'compare' => '='
                )
            ),
            'number' => 1
        ));
        
        // Если не нашли, пробуем найти без учета регистра (для совместимости)
        if (empty($users)) {
            $all_users = get_users(array(
                'meta_key' => 'tcm_verification_code',
                'meta_compare' => 'EXISTS'
            ));
            
            foreach ($all_users as $user) {
                $stored_code = get_user_meta($user->ID, 'tcm_verification_code', true);
                if (strtoupper(trim($stored_code)) === $verification_code) {
                    $users = array($user);
                    break;
                }
            }
        }
        
        if (empty($users)) {
            $this->send_reply($chat_id, 
                "❌ Неверный код верификации.\n\n" .
                "Проверьте код и попробуйте снова.\n\n" .
                "Код можно получить в админ-панели WordPress в разделе управления пользователями."
            );
            return false;
        }
        
        $user = $users[0];
        
        // Проверяем, не привязан ли уже этот Telegram ID
        $existing_user = $this->users->get_user_by_telegram_id($user_id_telegram);
        if ($existing_user && $existing_user->ID != $user->ID) {
            $this->send_reply($chat_id, 
                "❌ Этот Telegram аккаунт уже привязан к другому пользователю."
            );
            return false;
        }
        
        // Привязываем Telegram ID
        $result = $this->users->link_telegram_to_user($user->ID, $user_id_telegram, $from);
        
        if (!$result) {
            $this->send_reply($chat_id, 
                "❌ Ошибка при привязке аккаунта."
            );
            return false;
        }
        
        // Проверяем срок действия кода (24 часа)
        $code_created = get_user_meta($user->ID, 'tcm_verification_code_created', true);
        if ($code_created) {
            $code_expires_in = 24 * 3600; // 24 часа в секундах
            $time_passed = current_time('timestamp') - $code_created;
            
            if ($time_passed > $code_expires_in) {
                // Код истек
                delete_user_meta($user->ID, 'tcm_verification_code');
                delete_user_meta($user->ID, 'tcm_verification_code_created');
                $this->send_reply($chat_id, 
                    "❌ Код верификации истек.\n\n" .
                    "Срок действия кода составляет 24 часа. Обратитесь к администратору для получения нового кода."
                );
                return false;
            }
        }
        
        // Удаляем код верификации и время создания
        delete_user_meta($user->ID, 'tcm_verification_code');
        delete_user_meta($user->ID, 'tcm_verification_code_created');
        
        // Показываем главное меню с прилипающей клавиатурой
        $this->show_main_menu_with_reply_keyboard($chat_id);
        
        // Приветственное сообщение с инструкцией
        $welcome_message = 
            "👋 <b>Добро пожаловать!</b>\n\n" .
            "✅ Аккаунт успешно привязан!\n\n" .
            "📋 <b>Ваши данные:</b>\n" .
            "• Имя: " . esc_html($user->display_name) . "\n" .
            "• Username: " . esc_html($user->user_login) . "\n\n" .
            "📖 <b>Краткая инструкция:</b>\n\n" .
            "1️⃣ <b>Выбор категории</b>\n" .
            "Нажмите кнопку «📂 Выбор Шага» в меню, чтобы выбрать категорию для ваших записей.\n\n" .
            "2️⃣ <b>Создание записей</b>\n" .
            "Просто отправляйте сообщения боту — они автоматически будут созданы как записи в выбранной категории.\n\n" .
            "3️⃣ <b>Просмотр записей</b>\n" .
            "Используйте кнопку «📝 Мои записи» для просмотра ваших записей по Шагам, Главам и Точкам.\n\n" .
            "4️⃣ <b>Настройки</b>\n" .
            "В разделе «⚙️ Настройки» вы можете изменить выбранную категорию и другие параметры.\n\n" .
            "💡 <b>Совет:</b> Начните с выбора Шага через меню, затем отправляйте свои записи боту.\n\n" .
            "Желаем успехов в работе! 🚀";
        
        $this->send_reply($chat_id, $welcome_message);
        
        return true;
    }
    
    /**
     * Обработка команды /status
     */
    private function handle_status($chat_id, $user_id_telegram) {
        $user = $this->users->get_user_by_telegram_id($user_id_telegram);
        
        if (!$user) {
            $this->send_reply($chat_id, 
                "❌ Вы не зарегистрированы.\n\n" .
                "Используйте /register <имя> для регистрации."
            );
            return false;
        }
        
        $telegram_username = get_user_meta($user->ID, 'tcm_telegram_username', true);
        $linked_at = get_user_meta($user->ID, 'tcm_telegram_linked_at', true);
        
        $status_text = 
            "✅ Вы зарегистрированы!\n\n" .
            "Имя: " . $user->display_name . "\n" .
            "Username: " . $user->user_login . "\n";
        
        if ($telegram_username) {
            $status_text .= "Telegram: @" . $telegram_username . "\n";
        }
        
        if ($linked_at) {
            $status_text .= "Привязан: " . date('d.m.Y H:i', strtotime($linked_at)) . "\n";
        }
        
        $status_text .= "\nОтправляйте сообщения боту, чтобы создавать записи на сайте.";
        
        $this->send_reply($chat_id, $status_text);
        
        return true;
    }
    
    /**
     * Создание записи из сообщения Telegram
     * 
     * @param string $text Текст сообщения
     * @param int $category_id ID категории
     * @param string $chat_id ID чата в Telegram
     * @param string $message_id ID сообщения в Telegram
     * @param int $author_id ID автора (если не указан, используется по умолчанию)
     * @return int|WP_Error ID созданной записи или ошибка
     */
    private function create_post_from_message($text, $category_id, $chat_id, $message_id, $author_id = null) {
        $category = get_category($category_id);
        if (!$category) {
            return new WP_Error('tcm_invalid_category', 'Неверная категория');
        }
        
        // Получаем автора - если не указан, используем по умолчанию
        if (!$author_id) {
            $author_id = get_option('tcm_default_author', 1);
        }
        
        // Получаем количество записей пользователя в этой категории
        $user_posts = get_posts(array(
            'author' => $author_id,
            'category' => $category_id,
            'post_type' => 'post',
            'post_status' => array('publish', 'draft'),
            'fields' => 'ids',
        ));
        
        $post_count = count($user_posts) + 1;
        $category_name = $category->name;
        $author = get_userdata($author_id);
        $author_login = $author ? $author->user_login : 'admin';
        
        $post_title = $category_name . ' - ' . $author_login . ' - ' . $post_count;
        
        // Определяем статус публикации
        $publish_status = get_option('tcm_auto_publish', false) ? 'publish' : 'draft';
        
        $new_post = array(
            'post_title' => sanitize_text_field($post_title),
            'post_content' => wp_kses_post($text),
            'post_status' => $publish_status,
            'post_category' => array($category_id),
            'post_author' => $author_id,
        );
        
        $post_id = wp_insert_post($new_post);
        
        if (is_wp_error($post_id)) {
            return $post_id;
        }
        
        if ($post_id) {
            // Сохраняем мета-данные
            update_post_meta($post_id, '_telegram_chat_id', $chat_id);
            update_post_meta($post_id, '_telegram_message_id', $message_id);
            update_post_meta($post_id, '_from_telegram', '1');
            update_post_meta($post_id, '_telegram_created_at', current_time('mysql'));
            
            // Если нужно опубликовать
            if (get_option('tcm_set_publish_flag', false)) {
                update_post_meta($post_id, 'publish_post', '1');
            }
            
            // Сохраняем в таблицу
            $this->save_telegram_post($post_id, $category_id, $chat_id, $message_id);
        }
        
        return $post_id;
    }
    
    /**
     * Получение категории для чата
     * 
     * @param string $chat_id ID чата в Telegram
     * @param string $user_id_telegram ID пользователя в Telegram (опционально)
     * @return int|false ID категории или false
     */
    private function get_category_for_chat($chat_id, $user_id_telegram = '') {
        // Сначала проверяем сохраненную категорию для пользователя (если указан user_id)
        if (!empty($user_id_telegram)) {
            $user_categories = get_option('tcm_user_categories', array());
            if (isset($user_categories[$user_id_telegram]) && $user_categories[$user_id_telegram] > 0) {
                return intval($user_categories[$user_id_telegram]);
            }
        }
        
        // Затем проверяем настройки чата
        $chat_categories = get_option('tcm_chat_categories', array());
        if (isset($chat_categories[$chat_id]) && $chat_categories[$chat_id] > 0) {
            return intval($chat_categories[$chat_id]);
        }
        
        // Если не найдено, возвращаем категорию по умолчанию
        return get_option('tcm_default_category', false);
    }
    
    /**
     * Отправка ответа в Telegram
     * 
     * @param string $chat_id ID чата
     * @param string $text Текст сообщения
     * @return array|WP_Error
     */
    public function send_reply($chat_id, $text) {
        $log_enabled = defined('WP_DEBUG') && WP_DEBUG;
        $token = get_option('tcm_telegram_token', '');
        
        if (empty($token)) {
            if ($log_enabled) {
                error_log('TCM: No Telegram token configured');
            }
            return new WP_Error('tcm_no_token', 'Telegram токен не настроен');
        }
        
        $url = "https://api.telegram.org/bot{$token}/sendMessage";
        
        $args = array(
            'body' => array(
                'chat_id' => $chat_id,
                'text' => $text,
                'parse_mode' => 'HTML'
            ),
            'timeout' => 30
        );
        
        if ($log_enabled) {
            error_log('TCM: Sending reply to chat ' . $chat_id);
            error_log('TCM: Message text (first 200 chars): ' . substr($text, 0, 200));
        }
        
        $response = wp_remote_post($url, $args);
        
        if (is_wp_error($response)) {
            if ($log_enabled) {
                error_log('TCM: Send error: ' . $response->get_error_message());
                error_log('TCM: Response code: ' . $response->get_error_code());
            }
            return $response;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if ($log_enabled) {
            error_log('TCM: Response code: ' . $response_code);
            error_log('TCM: Response body: ' . print_r($body, true));
        }
        
        if (isset($body['ok']) && $body['ok'] === true) {
            if ($log_enabled) {
                error_log('TCM: Message sent successfully');
            }
            return $body;
        }
        
        $error_msg = isset($body['description']) ? $body['description'] : 'Ошибка отправки в Telegram';
        if ($log_enabled) {
            error_log('TCM: Telegram API error: ' . $error_msg);
            error_log('TCM: Full response: ' . print_r($body, true));
        }
        
        return new WP_Error('tcm_telegram_error', $error_msg);
    }
    
    /**
     * Сохранение информации о созданной записи
     * 
     * @param int $post_id ID записи
     * @param int $category_id ID категории
     * @param string $chat_id ID чата в Telegram
     * @param string $message_id ID сообщения в Telegram
     */
    private function save_telegram_post($post_id, $category_id, $chat_id, $message_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'tcm_telegram_posts';
        
        $wpdb->insert(
            $table_name,
            array(
                'post_id' => $post_id,
                'category_id' => $category_id,
                'telegram_message_id' => $message_id,
                'telegram_chat_id' => $chat_id,
                'created_at' => current_time('mysql')
            ),
            array('%d', '%d', '%s', '%s', '%s')
        );
    }
    
    /**
     * Настройка webhook для Telegram
     * 
     * @param string $webhook_url URL для webhook
     * @return array|WP_Error
     */
    public function set_webhook($webhook_url) {
        $token = get_option('tcm_telegram_token', '');
        
        if (empty($token)) {
            return new WP_Error('tcm_no_token', 'Telegram токен не настроен');
        }
        
        $url = "https://api.telegram.org/bot{$token}/setWebhook";
        
        $args = array(
            'body' => array(
                'url' => $webhook_url
            ),
            'timeout' => 30
        );
        
        $response = wp_remote_post($url, $args);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($body['ok']) && $body['ok'] === true) {
            update_option('tcm_webhook_url', $webhook_url);
            
            // Устанавливаем меню команд
            $this->set_commands_menu();
            
            return $body;
        }
        
        return new WP_Error('tcm_webhook_error', isset($body['description']) ? $body['description'] : 'Ошибка настройки webhook');
    }
    
    /**
     * Удаление webhook
     * 
     * @return array|WP_Error
     */
    public function delete_webhook() {
        $token = get_option('tcm_telegram_token', '');
        
        if (empty($token)) {
            return new WP_Error('tcm_no_token', 'Telegram токен не настроен');
        }
        
        $url = "https://api.telegram.org/bot{$token}/deleteWebhook";
        
        $response = wp_remote_post($url, array('timeout' => 30));
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        delete_option('tcm_webhook_url');
        
        return $body;
    }
    
    /**
     * Получение информации о webhook
     * 
     * @return array|WP_Error
     */
    public function get_webhook_info() {
        $token = get_option('tcm_telegram_token', '');
        
        if (empty($token)) {
            return new WP_Error('tcm_no_token', 'Telegram токен не настроен');
        }
        
        $url = "https://api.telegram.org/bot{$token}/getWebhookInfo";
        
        $response = wp_remote_get($url, array('timeout' => 30));
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        return $body;
    }
    
    /**
     * Проверка, была ли запись создана из Telegram
     * 
     * @param int $post_id ID записи
     * @return bool
     */
    public function is_post_from_telegram($post_id) {
        return get_post_meta($post_id, '_from_telegram', true) == '1';
    }
    
    /**
     * Установка меню команд бота
     * 
     * @return array|WP_Error
     */
    public function set_commands_menu() {
        $token = get_option('tcm_telegram_token', '');
        
        if (empty($token)) {
            return new WP_Error('tcm_no_token', 'Telegram токен не настроен');
        }
        
        $url = "https://api.telegram.org/bot{$token}/setMyCommands";
        
        // Определяем команды для меню
        $commands = array(
            array(
                'command' => 'start',
                'description' => 'Показать справку и начать работу'
            ),
            array(
                'command' => 'help',
                'description' => 'Показать справку по командам'
            ),
            array(
                'command' => 'register',
                'description' => 'Зарегистрировать новый аккаунт (укажите имя)'
            ),
            array(
                'command' => 'link',
                'description' => 'Привязать Telegram к существующему аккаунту (укажите код)'
            ),
            array(
                'command' => 'status',
                'description' => 'Проверить статус регистрации'
            )
        );
        
        $args = array(
            'body' => json_encode(array(
                'commands' => $commands
            )),
            'headers' => array(
                'Content-Type' => 'application/json'
            ),
            'timeout' => 30
        );
        
        $response = wp_remote_post($url, $args);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($body['ok']) && $body['ok'] === true) {
            // Также устанавливаем прилипающее меню
            $this->set_menu_button();
            return $body;
        }
        
        return new WP_Error('tcm_commands_error', isset($body['description']) ? $body['description'] : 'Ошибка настройки меню команд');
    }
    
    /**
     * Установка прилипающего меню (Menu Button)
     * 
     * @return array|WP_Error
     */
    public function set_menu_button() {
        $token = get_option('tcm_telegram_token', '');
        
        if (empty($token)) {
            return new WP_Error('tcm_no_token', 'Telegram токен не настроен');
        }
        
        $url = "https://api.telegram.org/bot{$token}/setChatMenuButton";
        
        // Создаем меню с кнопками
        $menu_button = array(
            'type' => 'commands'
        );
        
        $args = array(
            'body' => json_encode(array(
                'menu_button' => $menu_button
            )),
            'headers' => array(
                'Content-Type' => 'application/json'
            ),
            'timeout' => 30
        );
        
        $response = wp_remote_post($url, $args);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($body['ok']) && $body['ok'] === true) {
            return $body;
        }
        
        return new WP_Error('tcm_menu_button_error', isset($body['description']) ? $body['description'] : 'Ошибка настройки прилипающего меню');
    }
    
    /**
     * Обработка callback_query (нажатие на кнопки)
     */
    private function handle_callback_query($callback_query) {
        $log_enabled = defined('WP_DEBUG') && WP_DEBUG;
        
        $chat_id = (string)$callback_query['message']['chat']['id'];
        $data = $callback_query['data'];
        $callback_id = $callback_query['id'];
        $user_id_telegram = (string)$callback_query['from']['id'];
        
        // Сохраняем callback_query в глобальной переменной для доступа в других функциях
        global $tcm_current_callback_query;
        $tcm_current_callback_query = $callback_query;
        
        if ($log_enabled) {
            error_log('TCM: Callback query received. Chat ID: ' . $chat_id . ', User ID: ' . $user_id_telegram . ', Data: ' . $data);
        }
        
        // Проверяем, не является ли это callback для регистрации (выбор проблем)
        $parts = explode(':', $data, 2);
        $action = $parts[0];
        $param = isset($parts[1]) ? $parts[1] : '';
        
        if ($action == 'registration') {
            // Для регистрации не проверяем существование пользователя
            // Подтверждаем получение callback
            $this->answer_callback_query($callback_id);
            
            // Обрабатываем регистрацию
            $registration_parts = explode(':', $param, 2);
            $registration_action = isset($registration_parts[0]) ? $registration_parts[0] : '';
            $registration_param = isset($registration_parts[1]) ? $registration_parts[1] : '';
            
            if ($registration_action == 'select_problem') {
                return $this->handle_problem_selection($chat_id, $registration_param, $user_id_telegram, $callback_id);
            } elseif ($registration_action == 'finish_problems') {
                $from = isset($callback_query['from']) ? $callback_query['from'] : array();
                return $this->finish_registration_with_problems($chat_id, $user_id_telegram, $from);
            }
            return false;
        }
        
        // Подтверждаем получение callback
        $this->answer_callback_query($callback_id);
        
        // Проверяем регистрацию
        $user = $this->users->get_user_by_telegram_id($user_id_telegram);
        if (!$user) {
            // Пользователь не зарегистрирован - запрашиваем имя для регистрации
            // Сохраняем состояние ожидания имени
            update_option('tcm_waiting_name_' . $user_id_telegram, true);
            
            $this->send_reply($chat_id, 
                "👋 <b>Регистрация</b>\n\n" .
                "Для начала работы необходимо зарегистрироваться.\n\n" .
                "📝 Пожалуйста, отправьте ваше имя (например: Иван Иванов)"
            );
            return false;
        }
        
        // Парсим данные callback
        $parts = explode(':', $data, 2);
        $action = $parts[0];
        $param = isset($parts[1]) ? $parts[1] : '';
        
        switch ($action) {
            case 'menu':
                return $this->show_main_menu_with_user($chat_id, $user_id_telegram);
                
            case 'category':
                return $this->show_category_selection($chat_id, $param, $user_id_telegram);
                
            case 'select_category':
                // Передаем user_id_telegram для сохранения выбора на пользователя
                if ($log_enabled) {
                    error_log('TCM: Processing select_category callback. Category ID: ' . $param . ', User ID: ' . $user_id_telegram);
                }
                return $this->select_category($chat_id, $param, $user_id_telegram);
                
            case 'settings':
                return $this->show_settings($chat_id, $user_id_telegram);
                
            case 'help':
                return $this->show_help($chat_id);
                
            case 'support':
                return $this->show_support($chat_id, $user_id_telegram);
            
            case 'support_send_message':
                return $this->handle_support_send_message($chat_id, $user_id_telegram);
            
            case 'reminder_settings':
                return $this->show_reminder_settings($chat_id, $user_id_telegram);
            
            case 'set_reminder_time':
                return $this->handle_set_reminder_time($chat_id, $user_id_telegram, $param);
            
            case 'disable_reminder':
                return $this->handle_disable_reminder($chat_id, $user_id_telegram);
            
            case 'timezone_settings':
                return $this->show_timezone_settings($chat_id, $user_id_telegram);
            
            case 'set_timezone':
                return $this->handle_set_timezone($chat_id, $user_id_telegram, $param);
                
            case 'register':
                return $this->show_register_info($chat_id);
                
            case 'link':
                return $this->show_link_info($chat_id);
                
            case 'status':
                return $this->handle_status($chat_id, $user_id_telegram);
                
            case 'questionnaire':
                return $this->handle_questionnaire($chat_id, $param, $user_id_telegram);
                
            case 'consent':
                return $this->handle_consent($chat_id, $param, $user_id_telegram);
                
            case 'skip_question':
                return $this->handle_skip_question($chat_id, $param, $user_id_telegram);
            
            case 'continue_ai_help_without_answer':
                return $this->handle_continue_ai_help_without_answer($chat_id, $user_id_telegram);
                
            case 'ai_assistant':
                return $this->handle_ai_assistant($chat_id, $param, $user_id_telegram);
                
            case 'ai_help':
                return $this->handle_ai_help($chat_id, $param, $user_id_telegram);
            
            case 'select_option':
                return $this->handle_select_option($chat_id, $param, $user_id_telegram);
            
            case 'finish':
                return $this->handle_finish_question($chat_id, $param, $user_id_telegram);
                
            case 'ai_help_refresh':
                return $this->handle_ai_help_refresh($chat_id, $param, $user_id_telegram);
                
            case 'ai_help_clear_history':
                return $this->handle_ai_help_clear_history($chat_id, $user_id_telegram);
                
            case 'pro_details':
                return $this->handle_pro_details($chat_id, $user_id_telegram);
            
            case 'view_posts':
                return $this->handle_view_posts($chat_id, $param, $user_id_telegram);
            
            case 'view_last_post':
                return $this->handle_view_last_post($chat_id, $user_id_telegram);
            
            case 'view_current_step':
                return $this->handle_view_current_category($chat_id, $user_id_telegram, 'step');
            
            case 'view_current_chapter':
                return $this->handle_view_current_category($chat_id, $user_id_telegram, 'chapter');
            
            case 'view_current_point':
                return $this->handle_view_current_category($chat_id, $user_id_telegram, 'point');
            
            case 'view_post':
                return $this->handle_view_post($chat_id, $param, $user_id_telegram);
            
            case 'edit_post':
                return $this->handle_edit_post($chat_id, $param, $user_id_telegram);
            
            case 'cancel_edit_post':
                return $this->handle_cancel_edit_post($chat_id, $user_id_telegram);
            
            case 'export_posts':
                return $this->handle_export_posts($chat_id, $param, $user_id_telegram);
            
            case 'show_posts':
                return $this->handle_show_posts($chat_id, $param, $user_id_telegram);
            
            case 'custom_category':
                // Обрабатываем разные варианты: menu, step_view, chapter_view, point_view, или ID категории
                if ($param === 'menu') {
                    return $this->handle_custom_category($chat_id, 'menu', $user_id_telegram);
                } elseif (strpos($param, 'step_view:') === 0) {
                    // Просмотр Глав Шага
                    $step_id = intval(str_replace('step_view:', '', $param));
                    return $this->show_step_chapters($chat_id, $step_id, $user_id_telegram);
                } elseif (strpos($param, 'chapter_view:') === 0) {
                    // Просмотр Точек Главы
                    $chapter_id = intval(str_replace('chapter_view:', '', $param));
                    return $this->show_chapter_points($chat_id, $chapter_id, $user_id_telegram);
                } elseif (strpos($param, 'point_view:') === 0) {
                    // Просмотр записей Точки, сгруппированных по Главам
                    $point_id = intval(str_replace('point_view:', '', $param));
                    return $this->show_point_posts_grouped($chat_id, $point_id, $user_id_telegram);
                } else {
                    // Это ID категории (Шаг) - показываем Главы
                    return $this->show_step_chapters($chat_id, intval($param), $user_id_telegram);
                }
                return false;
            
            case 'go_to_next_point':
                // Переход в следующую точку
                $next_point_id = intval($param);
                $wp_user_id = $this->get_wp_user_id($user_id_telegram);
                if ($wp_user_id) {
                    // Сохраняем выбор категории для пользователя
                    update_user_meta($wp_user_id, 'tcm_selected_category_' . $chat_id, $next_point_id);
                    update_user_meta($wp_user_id, 'tcm_selected_category', $next_point_id);
                }
                // Выбираем следующую точку
                $this->select_category($chat_id, $next_point_id, $user_id_telegram);
                $this->answer_callback_query($callback_query['id'], '✅ Переход в следующую точку выполнен');
                return true;
            
            case 'copy_point_name':
                // Копирование названия точки
                $point_id = intval($param);
                $point = get_category($point_id);
                if ($point) {
                    // Промпт больше не выводится при выборе точки
                    $this->answer_callback_query($callback_query['id'], 'Название точки скопировано');
                }
                return true;
            
            default:
                if ($log_enabled) {
                    error_log('TCM: Unknown callback action: ' . $action);
                }
                return false;
        }
    }
    
    /**
     * Подтверждение получения callback_query
     */
    private function answer_callback_query($callback_id, $text = '', $show_alert = false) {
        $token = get_option('tcm_telegram_token', '');
        if (empty($token)) {
            return false;
        }
        
        $url = "https://api.telegram.org/bot{$token}/answerCallbackQuery";
        $args = array(
            'body' => array(
                'callback_query_id' => $callback_id,
                'text' => $text,
                'show_alert' => $show_alert
            ),
            'timeout' => 10
        );
        
        wp_remote_post($url, $args);
        return true;
    }
    
    /**
     * Показ главного меню
     */
    private function show_main_menu($chat_id) {
        // Получаем user_id_telegram из текущего контекста
        // Если вызывается из callback, нужно передавать user_id_telegram
        // Для упрощения, получаем из последнего сообщения или используем chat_id как fallback
        $user_id_telegram = $chat_id; // Временное решение, нужно будет передавать user_id_telegram
        
        $keyboard = array(
            array(
                array('text' => '📂 Выбор Шага', 'callback_data' => 'category:0')
            ),
            array(
                array('text' => '📝 Мои записи', 'callback_data' => 'view_posts:menu'),
                array('text' => '📄 Последняя запись', 'callback_data' => 'view_last_post')
            )
        );
        
        // Проверяем, есть ли выбранная точка для помощи ИИ - показываем кнопку всегда, если есть точка
        $current_category_id = $this->get_category_for_chat($chat_id, $user_id_telegram);
        if ($current_category_id > 0) {
            $point_id = $this->get_category_at_level($current_category_id, 2);
            if ($point_id) {
                $point = get_category($point_id);
                if ($point) {
                    $wp_user_id = $this->get_wp_user_id($user_id_telegram);
                    $is_pro = $wp_user_id ? get_user_meta($wp_user_id, 'tcm_pro_subscription', true) : false;
                    
                    if ($is_pro) {
                        $keyboard[] = array(
                            array('text' => '🤖 Получить помощь ИИ по текущей точке', 'callback_data' => 'ai_help:' . $point_id)
                        );
                    } else {
                        $keyboard[] = array(
                            array('text' => '⭐ PRO 🤖 Получить помощь ИИ по текущей точке', 'callback_data' => 'ai_help:' . $point_id)
                        );
                    }
                }
            }
        }
        
        $keyboard[] = array(
            array('text' => '⚙️ Настройки', 'callback_data' => 'settings'),
            array('text' => '❓ Справка', 'callback_data' => 'help')
        );
        $keyboard[] = array(
            array('text' => '💬 Техподдержка', 'callback_data' => 'support')
        );
        
        $text = "🤖 <b>Главное меню</b>\n\n" .
                "Выберите действие:";
        
        return $this->send_reply_with_keyboard($chat_id, $text, $keyboard);
    }
    
    /**
     * Показ главного меню с user_id_telegram для проверки следующей точки
     */
    private function show_main_menu_with_user($chat_id, $user_id_telegram) {
        $keyboard = array(
            array(
                array('text' => '📂 Выбор Шага', 'callback_data' => 'category:0')
            ),
            array(
                array('text' => '📝 Мои записи', 'callback_data' => 'view_posts:menu'),
                array('text' => '📄 Последняя запись', 'callback_data' => 'view_last_post')
            )
        );
        
        // Получаем текущую выбранную категорию
        $current_category_id = $this->get_category_for_chat($chat_id, $user_id_telegram);
        $current_info = '';
        
        if ($current_category_id > 0) {
            $wp_user_id = $this->get_wp_user_id($user_id_telegram);
            $step_id = $this->get_category_at_level($current_category_id, 0);
            $chapter_id = $this->get_category_at_level($current_category_id, 1);
            $point_id = $this->get_category_at_level($current_category_id, 2);
            
            if ($point_id) {
                $point = get_category($point_id);
                if ($point) {
                    $posts_count = $wp_user_id ? $this->get_category_posts_count($point_id, $wp_user_id) : 0;
                    $current_info = "\n📍 <b>Текущая Точка:</b> " . esc_html($point->name);
                    if ($posts_count > 0) {
                        $current_info .= ' (' . $posts_count . ')';
                    }
                }
            } elseif ($chapter_id) {
                $chapter = get_category($chapter_id);
                if ($chapter) {
                    $current_info = "\n📖 <b>Текущая Глава:</b> " . esc_html($chapter->name);
                }
            } elseif ($step_id) {
                $step = get_category($step_id);
                if ($step) {
                    $current_info = "\n📚 <b>Текущий Шаг:</b> " . esc_html($step->name);
                }
            }
        }
        
        // Проверяем, есть ли следующая точка для текущей выбранной категории
        $next_point = $this->get_next_point_for_user($chat_id, $user_id_telegram);
        if ($next_point) {
            $next_point_name_short = mb_strlen($next_point['name']) > 30 ? mb_substr($next_point['name'], 0, 27) . '...' : $next_point['name'];
            $keyboard[] = array(
                array('text' => '➡️ Перейти в следующую точку: ' . $next_point_name_short, 'callback_data' => 'go_to_next_point:' . $next_point['id'])
            );
        }
        
        // Проверяем, есть ли выбранная точка для помощи ИИ - показываем кнопку всегда, если есть точка
        if ($current_category_id > 0) {
            $point_id = $this->get_category_at_level($current_category_id, 2);
            if ($point_id) {
                $point = get_category($point_id);
                if ($point) {
                    $wp_user_id = $this->get_wp_user_id($user_id_telegram);
                    $is_pro = $wp_user_id ? get_user_meta($wp_user_id, 'tcm_pro_subscription', true) : false;
                    
                    if ($is_pro) {
                        $keyboard[] = array(
                            array('text' => '🤖 Получить помощь ИИ по текущей точке', 'callback_data' => 'ai_help:' . $point_id)
                        );
                    } else {
                        $keyboard[] = array(
                            array('text' => '⭐ PRO 🤖 Получить помощь ИИ по текущей точке', 'callback_data' => 'ai_help:' . $point_id)
                        );
                    }
                }
            }
        }
        
        $keyboard[] = array(
            array('text' => '⚙️ Настройки', 'callback_data' => 'settings'),
            array('text' => '❓ Справка', 'callback_data' => 'help')
        );
        $keyboard[] = array(
            array('text' => '💬 Техподдержка', 'callback_data' => 'support')
        );
        
        $text = "🤖 <b>Главное меню</b>" . $current_info . "\n\n" .
                "Выберите действие:";
        
        return $this->send_reply_with_keyboard($chat_id, $text, $keyboard);
    }
    
    /**
     * Показ главного меню с прилипающей клавиатурой (Reply Keyboard)
     */
    private function show_main_menu_with_reply_keyboard($chat_id) {
        // Пытаемся определить следующую точку для пользователя (используем chat_id как fallback для user_id)
        $user_id_telegram = $chat_id;
        $next_point = $this->get_next_point_for_user($chat_id, $user_id_telegram);
        $next_point_row = array();
        if ($next_point) {
            $next_point_row[] = array('text' => '➡️ Перейти в следующую точку');
        }
        
        $keyboard = array(
            array(
                array('text' => '📂 Выбор Шага'),
                array('text' => '📝 Мои записи')
            )
        );
        
        // Проверяем, есть ли выбранная точка для помощи ИИ
        $current_category_id = $this->get_category_for_chat($chat_id, $user_id_telegram);
        if ($current_category_id > 0) {
            $point_id = $this->get_category_at_level($current_category_id, 2);
            if ($point_id) {
                $point = get_category($point_id);
                if ($point) {
                    $wp_user_id = $this->get_wp_user_id($user_id_telegram);
                    $is_pro = $wp_user_id ? get_user_meta($wp_user_id, 'tcm_pro_subscription', true) : false;
                    
                    if ($is_pro) {
                        $keyboard[] = array(
                            array('text' => '🤖 Получить помощь ИИ по текущей точке')
                        );
                    } else {
                        $keyboard[] = array(
                            array('text' => '⭐ PRO 🤖 Получить помощь ИИ по текущей точке')
                        );
                    }
                }
            }
        }
        
        $keyboard[] = array(
            array('text' => '⚙️ Настройки'),
            array('text' => '❓ Справка')
        );
        $keyboard[] = array(
            array('text' => '💬 Техподдержка')
        );
        
        if (!empty($next_point_row)) {
            $keyboard[] = $next_point_row;
        }
        
        $text = "🤖 <b>Главное меню</b>\n\n" .
                "Выберите действие из меню ниже:";
        
        return $this->send_reply_with_reply_keyboard($chat_id, $text, $keyboard);
    }
    
    /**
     * Отправка сообщения с прилипающей клавиатурой (Reply Keyboard)
     */
    private function send_reply_with_reply_keyboard($chat_id, $text, $keyboard) {
        $log_enabled = defined('WP_DEBUG') && WP_DEBUG;
        $token = get_option('tcm_telegram_token', '');
        
        if (empty($token)) {
            if ($log_enabled) {
                error_log('TCM: send_reply_with_reply_keyboard - Token is empty');
            }
            return new WP_Error('tcm_no_token', 'Telegram токен не настроен');
        }
        
        $url = "https://api.telegram.org/bot{$token}/sendMessage";
        
        $reply_markup = json_encode(array(
            'keyboard' => $keyboard,
            'resize_keyboard' => true,
            'persistent' => true,
            'one_time_keyboard' => false
        ));
        
        if ($log_enabled) {
            error_log('TCM: send_reply_with_reply_keyboard - Chat ID: ' . $chat_id);
            error_log('TCM: send_reply_with_reply_keyboard - Keyboard: ' . $reply_markup);
        }
        
        $body = array(
            'chat_id' => $chat_id,
            'text' => $text,
            'parse_mode' => 'HTML',
            'reply_markup' => $reply_markup
        );
        
        $args = array(
            'body' => $body,
            'timeout' => 30
        );
        
        $response = wp_remote_post($url, $args);
        
        if (is_wp_error($response)) {
            if ($log_enabled) {
                error_log('TCM: send_reply_with_reply_keyboard error: ' . $response->get_error_message());
            }
            return $response;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = json_decode(wp_remote_retrieve_body($response), true);
        
        if ($log_enabled) {
            error_log('TCM: send_reply_with_reply_keyboard response code: ' . $response_code);
            error_log('TCM: send_reply_with_reply_keyboard response body: ' . print_r($response_body, true));
        }
        
        if (isset($response_body['ok']) && $response_body['ok'] === true) {
            return $response_body;
        }
        
        $error_msg = isset($response_body['description']) ? $response_body['description'] : 'Ошибка отправки в Telegram';
        return new WP_Error('tcm_telegram_error', $error_msg);
    }
    
    /**
     * Обработка действий прилипающей клавиатуры
     */
    private function handle_reply_keyboard_action($text, $chat_id, $user_id_telegram) {
        $log_enabled = defined('WP_DEBUG') && WP_DEBUG;
        
        if ($log_enabled) {
            error_log('TCM: handle_reply_keyboard_action - Text: ' . $text . ', Chat ID: ' . $chat_id);
        }
        
        switch ($text) {
            case '📂 Выбор Шага':
                return $this->show_category_selection($chat_id, '0', $user_id_telegram);
            
            case '📝 Мои записи':
                $wp_user_id = $this->get_wp_user_id($user_id_telegram);
                if (!$wp_user_id) {
                    $this->send_reply_with_reply_keyboard($chat_id, 
                        '❌ Пользователь не найден. Пожалуйста, зарегистрируйтесь через /register или /link',
                        $this->get_main_reply_keyboard()
                    );
                    return false;
                }
                return $this->show_view_posts_menu($chat_id, $wp_user_id);
            
            case '➡️ Перейти в следующую точку':
                // Переход в следующую точку (reply-клавиатура)
                $next_point = $this->get_next_point_for_user($chat_id, $user_id_telegram);
                if ($next_point) {
                    // Сохраняем выбор и переходим
                    $this->select_category($chat_id, $next_point['id'], $user_id_telegram);
                } else {
                    $this->send_reply_with_reply_keyboard(
                        $chat_id,
                        '❌ Следующая точка не найдена. Выберите шаг/главу вручную.',
                        $this->get_main_reply_keyboard()
                    );
                }
                return true;
            
            case '⚙️ Настройки':
                return $this->show_settings($chat_id, $user_id_telegram);
            
            case '❓ Справка':
                return $this->show_help($chat_id);
            
            case '💬 Техподдержка':
                return $this->show_support($chat_id, $user_id_telegram);
            
            case '🏠 Главное меню':
                return $this->show_main_menu_with_reply_keyboard($chat_id);
            
            default:
                // Проверяем, является ли это кнопкой помощи ИИ
                if ($text === '🤖 Получить помощь ИИ по текущей точке' ||
                    $text === '⭐ PRO 🤖 Получить помощь ИИ по текущей точке') {
                    // Получаем текущую выбранную категорию
                    $current_category_id = $this->get_category_for_chat($chat_id, $user_id_telegram);
                    if ($current_category_id > 0) {
                        $point_id = $this->get_category_at_level($current_category_id, 2);
                        if ($point_id) {
                            return $this->handle_ai_help($chat_id, $point_id, $user_id_telegram);
                        } else {
                            $this->send_reply_with_reply_keyboard($chat_id, 
                                '❌ Точка не выбрана. Пожалуйста, выберите точку через меню "📂 Выбор Шага".',
                                $this->get_main_reply_keyboard()
                            );
                            return false;
                        }
                    } else {
                        $this->send_reply_with_reply_keyboard($chat_id, 
                            '❌ Точка не выбрана. Пожалуйста, выберите точку через меню "📂 Выбор Шага".',
                            $this->get_main_reply_keyboard()
                        );
                        return false;
                    }
                }
                
                if ($log_enabled) {
                    error_log('TCM: Unknown reply keyboard action: ' . $text);
                }
                return false;
        }
    }
    
    /**
     * Получение структуры главной прилипающей клавиатуры
     */
    private function get_main_reply_keyboard() {
        return array(
            array(
                array('text' => '📂 Выбор Шага'),
                array('text' => '📝 Мои записи')
            ),
            array(
                array('text' => '⚙️ Настройки'),
                array('text' => '❓ Справка')
            ),
            array(
                array('text' => '💬 Техподдержка')
            )
        );
    }
    
    /**
     * Получение названия уровня категории
     * 
     * @param int $category_id ID категории
     * @param string $case Падеж: 'nominative' (именительный), 'genitive' (родительный), 'accusative' (винительный), 'prepositional' (предложный)
     * @return string Название уровня в нужном падеже
     */
    private function get_category_level_name($category_id, $case = 'nominative') {
        $category = get_category($category_id);
        if (!$category) {
            return 'Категория';
        }
        
        // Определяем уровень вложенности
        $level = 0;
        $current = $category;
        while ($current && $current->parent > 0) {
            $level++;
            $current = get_category($current->parent);
            if (!$current) {
                break;
            }
        }
        
        // Возвращаем название в зависимости от уровня и падежа
        switch ($level) {
            case 0:
                switch ($case) {
                    case 'genitive':
                        return 'Шага';
                    case 'accusative':
                        return 'Шаг';
                    case 'prepositional':
                        return 'Шаге';
                    default:
                        return 'Шаг';
                }
            case 1:
                switch ($case) {
                    case 'genitive':
                        return 'Главы';
                    case 'accusative':
                        return 'Главу';
                    case 'prepositional':
                        return 'Главе';
                    default:
                        return 'Глава';
                }
            case 2:
                switch ($case) {
                    case 'genitive':
                        return 'Точки';
                    case 'accusative':
                        return 'Точку';
                    case 'prepositional':
                        return 'Точке';
                    default:
                        return 'Точка';
                }
            default:
                switch ($case) {
                    case 'genitive':
                        return 'Категории';
                    case 'accusative':
                        return 'Категорию';
                    case 'prepositional':
                        return 'Категории';
                    default:
                        return 'Категория';
                }
        }
    }
    
    /**
     * Получение названия уровня для дочерних категорий
     * 
     * @param int $parent_id ID родительской категории
     * @param string $case Падеж
     * @return string Название уровня в нужном падеже
     */
    private function get_child_level_name($parent_id, $case = 'nominative') {
        if ($parent_id == 0) {
            // Если родитель = 0, то дочерние - это Шаги (первый уровень)
            switch ($case) {
                case 'genitive':
                    return 'Шага';
                case 'accusative':
                    return 'Шаг';
                case 'prepositional':
                    return 'Шаге';
                default:
                    return 'Шаг';
            }
        }
        
        $parent = get_category($parent_id);
        if (!$parent) {
            return 'Категория';
        }
        
        // Определяем уровень родительской категории
        $level = 0;
        $current = $parent;
        while ($current && $current->parent > 0) {
            $level++;
            $current = get_category($current->parent);
            if (!$current) {
                break;
            }
        }
        
        // Дочерние категории будут на уровень выше
        switch ($level) {
            case 0:
                // Родитель - Шаг, дочерние - Главы
                switch ($case) {
                    case 'genitive':
                        return 'Главы';
                    case 'accusative':
                        return 'Главу';
                    case 'prepositional':
                        return 'Главе';
                    default:
                        return 'Глава';
                }
            case 1:
                // Родитель - Глава, дочерние - Точки
                switch ($case) {
                    case 'genitive':
                        return 'Точки';
                    case 'accusative':
                        return 'Точку';
                    case 'prepositional':
                        return 'Точке';
                    default:
                        return 'Точка';
                }
            default:
                switch ($case) {
                    case 'genitive':
                        return 'Категории';
                    case 'accusative':
                        return 'Категорию';
                    case 'prepositional':
                        return 'Категории';
                    default:
                        return 'Категория';
                }
        }
    }
    
    /**
     * Получение количества записей в категории (с учетом всех дочерних категорий)
     * 
     * @param int $category_id ID категории
     * @param int|null $user_id ID пользователя WordPress (если null, считает для всех пользователей)
     * @return int Количество записей
     */
    private function get_category_posts_count($category_id, $user_id = null) {
        $category = get_category($category_id);
        if (!$category) {
            return 0;
        }
        
        // Собираем все ID категорий (текущая + все дочерние)
        $category_ids = array($category_id);
        
        // Получаем все дочерние категории рекурсивно
        $this->get_all_child_category_ids($category_id, $category_ids);
        
        // Подсчитываем записи
        $args = array(
            'category__in' => $category_ids,
            'post_type' => 'post',
            'post_status' => array('publish', 'draft'),
            'numberposts' => -1,
            'fields' => 'ids'
        );
        
        if ($user_id !== null) {
            $args['author'] = $user_id;
        }
        
        $posts = get_posts($args);
        return count($posts);
    }
    
    /**
     * Рекурсивное получение всех дочерних категорий
     * 
     * @param int $parent_id ID родительской категории
     * @param array &$category_ids Массив для накопления ID категорий
     */
    private function get_all_child_category_ids($parent_id, &$category_ids) {
        $children = get_categories(array(
            'parent' => $parent_id,
            'hide_empty' => false
        ));
        
        foreach ($children as $child) {
            $category_ids[] = $child->term_id;
            $this->get_all_child_category_ids($child->term_id, $category_ids);
        }
    }
    
    /**
     * Получение следующей точки (следующей категории уровня "Точка")
     * 
     * @param int $current_point_id ID текущей точки
     * @return array|false Массив с данными следующей точки или false, если следующей нет
     */
    private function get_next_point($current_point_id) {
        $current_point = get_category($current_point_id);
        if (!$current_point) {
            return false;
        }
        
        // Определяем уровень текущей категории
        $level = 0;
        $current = $current_point;
        while ($current && $current->parent > 0) {
            $level++;
            $current = get_category($current->parent);
            if (!$current) {
                break;
            }
        }
        
        // Если это не точка (уровень 2), возвращаем false
        if ($level != 2) {
            return false;
        }
        
        // Получаем все точки в той же главе (родитель = parent текущей точки)
        $chapter_id = $current_point->parent;
        $all_points = get_categories(array(
            'parent' => $chapter_id,
            'hide_empty' => false,
            'orderby' => 'term_id',
            'order' => 'ASC'
        ));
        
        // Находим текущую точку в списке
        $current_index = -1;
        foreach ($all_points as $index => $point) {
            if ($point->term_id == $current_point_id) {
                $current_index = $index;
                break;
            }
        }
        
        // Если текущая точка найдена и есть следующая
        if ($current_index >= 0 && isset($all_points[$current_index + 1])) {
            $next_point = $all_points[$current_index + 1];
            return array(
                'id' => $next_point->term_id,
                'name' => $next_point->name,
                'category' => $next_point
            );
        }
        
        // Если следующей точки в главе нет, ищем следующую главу
        $chapter = get_category($chapter_id);
        if (!$chapter) {
            return false;
        }
        
        $step_id = $chapter->parent;
        $all_chapters = get_categories(array(
            'parent' => $step_id,
            'hide_empty' => false,
            'orderby' => 'term_id',
            'order' => 'ASC'
        ));
        
        // Находим текущую главу в списке
        $current_chapter_index = -1;
        foreach ($all_chapters as $index => $ch) {
            if ($ch->term_id == $chapter_id) {
                $current_chapter_index = $index;
                break;
            }
        }
        
        // Ищем следующую главу с точками
        if ($current_chapter_index >= 0) {
            for ($i = $current_chapter_index + 1; $i < count($all_chapters); $i++) {
                $next_chapter = $all_chapters[$i];
                $points_in_chapter = get_categories(array(
                    'parent' => $next_chapter->term_id,
                    'hide_empty' => false,
                    'orderby' => 'term_id',
                    'order' => 'ASC',
                    'number' => 1
                ));
                
                if (!empty($points_in_chapter)) {
                    // Нашли следующую главу с точками, возвращаем первую точку
                    $first_point = $points_in_chapter[0];
                    return array(
                        'id' => $first_point->term_id,
                        'name' => $first_point->name,
                        'category' => $first_point
                    );
                }
            }
        }
        
        return false;
    }
    
    /**
     * Получение следующей точки для текущей выбранной категории пользователя
     * 
     * @param string $chat_id ID чата
     * @param string $user_id_telegram Telegram ID пользователя
     * @return array|false Массив с данными следующей точки или false
     */
    private function get_next_point_for_user($chat_id, $user_id_telegram) {
        // Получаем выбранную категорию пользователя
        $wp_user_id = $this->get_wp_user_id($user_id_telegram);
        $category_id = 0;
        
        if ($wp_user_id) {
            // Сначала пробуем получить из user meta (приоритет)
            $category_id = get_user_meta($wp_user_id, 'tcm_selected_category_' . $chat_id, true);
            if (!$category_id) {
                $category_id = get_user_meta($wp_user_id, 'tcm_selected_category', true);
            }
        }
        
        // Если не нашли в user meta, пробуем из опций
        if (!$category_id) {
            $chat_categories = get_option('tcm_chat_categories', array());
            $category_id = isset($chat_categories[$chat_id]) ? $chat_categories[$chat_id] : 0;
        }
        
        if (!$category_id) {
            return false;
        }
        
        // Проверяем, является ли выбранная категория точкой
        $current_category = get_category($category_id);
        if (!$current_category) {
            return false;
        }
        
        // Определяем уровень категории
        $level = 0;
        $current = $current_category;
        while ($current && $current->parent > 0) {
            $level++;
            $current = get_category($current->parent);
            if (!$current) {
                break;
            }
        }
        
        // Если это точка (уровень 2), получаем следующую точку
        if ($level == 2) {
            return $this->get_next_point($category_id);
        }
        
        return false;
    }
    
    /**
     * Предложение перехода в следующую точку
     * 
     * @param string $chat_id ID чата
     * @param string $user_id_telegram Telegram ID пользователя
     * @param int $current_point_id ID текущей точки
     */
    private function offer_next_point($chat_id, $user_id_telegram, $current_point_id) {
        $next_point = $this->get_next_point($current_point_id);
        
        $current_point = get_category($current_point_id);
        $current_name = $current_point ? $current_point->name : 'текущей точки';
        
        // Формируем кнопки
        $keyboard = array();
        
        // Первая строка: информация о текущей точке
        if ($current_point) {
            $point_name_display = mb_strlen($current_name) > 35 ? mb_substr($current_name, 0, 32) . '...' : $current_name;
            $keyboard[] = array(
                array('text' => '📍 Вы в точке: ' . $point_name_display, 'callback_data' => 'copy_point_name:' . $current_point_id)
            );
        }
        
        if (!$next_point) {
            // Нет следующей точки - только кнопка редактирования
            $keyboard[] = array(
                array('text' => '✏️ Редактировать точку', 'callback_data' => 'select_category:' . $current_point_id)
            );
            
            $message = "🎯 <b>Что дальше?</b>\n\n" .
                      "Это последняя точка в программе.\n\n" .
                      "💡 Вы также можете в любое время оставить запись для текущей точки через меню.";
            
            $this->send_reply_with_keyboard($chat_id, $message, $keyboard);
            return;
        }
        
        // Есть следующая точка
        $message = "🎯 <b>Что дальше?</b>\n\n" .
                  "Вы можете перейти в следующую точку: <b>" . esc_html($next_point['name']) . "</b>\n\n" .
                  "💡 Вы также можете в любое время оставить запись для текущей точки через меню.";
        
        // Вторая строка: кнопки редактирования и перехода
        $keyboard[] = array(
            array('text' => '✏️ Редактировать точку', 'callback_data' => 'select_category:' . $current_point_id),
            array('text' => '➡️ Перейти к следующей точке', 'callback_data' => 'go_to_next_point:' . $next_point['id'])
        );
        
        $this->send_reply_with_keyboard($chat_id, $message, $keyboard);
    }
    
    /**
     * Показ выбора категорий (иерархический)
     */
    private function show_category_selection($chat_id, $parent_id = '0', $user_id_telegram = '') {
        $parent_id = intval($parent_id);
        
        // Получаем текущую выбранную категорию для пользователя/чата
        $selected_category_id = $this->get_category_for_chat($chat_id, $user_id_telegram);
        
        // Получаем WordPress ID пользователя для подсчета записей
        $wp_user_id = $this->get_wp_user_id($user_id_telegram);
        
        // Получаем категории с сортировкой как в навигации (по term_id)
        $args = array(
            'parent' => $parent_id,
            'hide_empty' => false,
            'orderby' => 'term_id',
            'order' => 'ASC'
        );
        
        $categories = get_categories($args);
        
        if (empty($categories)) {
            // Если нет категорий и это конечная категория, предлагаем выбрать её
            if ($parent_id > 0) {
                $parent_category = get_category($parent_id);
                if ($parent_category) {
                    return $this->select_category($chat_id, $parent_id, (string)$chat_id);
                }
            }
            $this->send_reply($chat_id, "❌ Категории не найдены.");
            return $this->show_main_menu($chat_id);
        }
        
        $keyboard = array();
        
        // Кнопка "Назад" если есть родительская категория
        if ($parent_id > 0) {
            $parent_category = get_category($parent_id);
            if ($parent_category && $parent_category->parent > 0) {
                $keyboard[] = array(
                    array('text' => '⬅️ Назад', 'callback_data' => 'category:' . $parent_category->parent)
                );
            } else {
                $keyboard[] = array(
                    array('text' => '⬅️ Назад в меню', 'callback_data' => 'menu')
                );
            }
        }
        
        // Определяем название уровня для текущего списка (родительный падеж для "Выбор...")
        $level_name_genitive = $this->get_child_level_name($parent_id, 'genitive');
        $level_name_nominative = $this->get_child_level_name($parent_id, 'nominative');
        
        // Кнопки категорий (в один столбец)
        $step_number = 0; // Счетчик для нумерации шагов
        foreach ($categories as $category) {
            // Проверяем, есть ли дочерние категории
            $has_children = get_categories(array(
                'parent' => $category->term_id, 
                'hide_empty' => false
            ));
            
            // Проверяем, является ли эта категория выбранной
            $is_selected = ($selected_category_id == $category->term_id);
            
            // Выбираем иконку в зависимости от наличия дочерних категорий и выбранности
            if ($is_selected) {
                // Выбранная категория - используем цветные иконки
                $icon = !empty($has_children) ? '🟢📁' : '🟢📄';
            } else {
                // Не выбранная категория - обычные иконки
                $icon = !empty($has_children) ? '📁' : '📄';
            }
            
            // Для шагов (parent_id = 0) добавляем нумерацию
            $category_name = $category->name;
            if ($parent_id == 0) {
                $step_number++;
                $category_name = $step_number . 'Шаг ' . $category_name;
            }
            
            // Получаем количество записей пользователя в этой категории
            $posts_count = $wp_user_id ? $this->get_category_posts_count($category->term_id, $wp_user_id) : 0;
            
            // Формируем текст кнопки: (количество) иконка название
            $button_text = '';
            if ($posts_count > 0) {
                $button_text = '(' . $posts_count . ') ' . $icon . ' ' . $category_name;
            } else {
                $button_text = $icon . ' ' . $category_name;
            }
            
            // Каждая категория в отдельном ряду (один столбец)
            $keyboard[] = array(
                array(
                    'text' => $button_text,
                    'callback_data' => !empty($has_children) ? 'category:' . $category->term_id : 'select_category:' . $category->term_id
                )
            );
        }
        
        // Кнопка "Главное меню"
        $keyboard[] = array(
            array('text' => '🏠 Главное меню', 'callback_data' => 'menu')
        );
        
        // Получаем информацию о текущей выбранной точке
        $current_info = '';
        if ($selected_category_id > 0) {
            $wp_user_id = $this->get_wp_user_id($user_id_telegram);
            $step_id = $this->get_category_at_level($selected_category_id, 0);
            $chapter_id = $this->get_category_at_level($selected_category_id, 1);
            $point_id = $this->get_category_at_level($selected_category_id, 2);
            
            if ($point_id) {
                $point = get_category($point_id);
                if ($point) {
                    $posts_count = $wp_user_id ? $this->get_category_posts_count($point_id, $wp_user_id) : 0;
                    $current_info = "\n📍 <b>Текущая Точка:</b> " . esc_html($point->name);
                    if ($posts_count > 0) {
                        $current_info .= ' (' . $posts_count . ')';
                    }
                }
            } elseif ($chapter_id) {
                $chapter = get_category($chapter_id);
                if ($chapter) {
                    $current_info = "\n📖 <b>Текущая Глава:</b> " . esc_html($chapter->name);
                }
            } elseif ($step_id) {
                $step = get_category($step_id);
                if ($step) {
                    $current_info = "\n📚 <b>Текущий Шаг:</b> " . esc_html($step->name);
                }
            }
        }
        
        $text = "📂 <b>Выбор " . $level_name_genitive . "</b>" . $current_info . "\n\n";
        if ($parent_id > 0) {
            $parent = get_category($parent_id);
            if ($parent) {
                $parent_level_name = $this->get_category_level_name($parent_id, 'genitive');
                $text .= $parent_level_name . ": <b>" . esc_html($parent->name) . "</b>\n\n";
                
                // Добавляем описание родительской категории, если оно есть
                $parent_description = category_description($parent_id);
                if (!empty($parent_description)) {
                    $text .= "📝 <b>Описание:</b>\n";
                    // Убираем HTML-теги и HTML-сущности (включая &nbsp;)
                    $clean_description = wp_strip_all_tags($parent_description);
                    $clean_description = html_entity_decode($clean_description, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $clean_description = str_replace('&nbsp;', ' ', $clean_description);
                    $clean_description = preg_replace('/\s+/', ' ', $clean_description); // Убираем множественные пробелы
                    $clean_description = trim($clean_description);
                    $text .= $clean_description . "\n\n";
                }
            }
        }
        $text .= "Выберите " . $this->get_child_level_name($parent_id, 'accusative') . ":";
        
        return $this->send_reply_with_keyboard($chat_id, $text, $keyboard);
    }
    
    /**
     * Выбор категории для чата
     */
    private function select_category($chat_id, $category_id, $user_id_telegram) {
        $log_enabled = defined('WP_DEBUG') && WP_DEBUG;
        
        if ($log_enabled) {
            error_log('TCM: select_category called. Chat ID: ' . $chat_id . ', Category ID: ' . $category_id . ', User ID: ' . $user_id_telegram);
        }
        
        $category_id = intval($category_id);
        $category = get_category($category_id);
        
        if (!$category) {
            if ($log_enabled) {
                error_log('TCM: Category not found. ID: ' . $category_id);
            }
            $this->send_reply($chat_id, "❌ Категория не найдена.");
            return false;
        }
        
        // Проверяем, есть ли дочерние категории
        $has_children = get_categories(array(
            'parent' => $category_id, 
            'hide_empty' => false
        ));
        
        if ($log_enabled) {
            error_log('TCM: Category has children: ' . (empty($has_children) ? 'no' : 'yes'));
        }
        
        // Если есть дочерние категории, показываем описание и затем дочерние категории
        if (!empty($has_children)) {
            if ($log_enabled) {
                error_log('TCM: Showing children categories instead of selecting');
            }
            
            // Получаем описание категории
            $category_description = category_description($category_id);
            if (!empty($category_description)) {
                $level_name = $this->get_category_level_name($category_id, 'nominative');
                $description_text = "📋 <b>" . $level_name . ": " . esc_html($category->name) . "</b>\n\n";
                // Убираем HTML-теги и HTML-сущности (включая &nbsp;)
                $clean_description = wp_strip_all_tags($category_description);
                $clean_description = html_entity_decode($clean_description, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $clean_description = str_replace('&nbsp;', ' ', $clean_description);
                $clean_description = preg_replace('/\s+/', ' ', $clean_description); // Убираем множественные пробелы
                $clean_description = trim($clean_description);
                $description_text .= $clean_description . "\n\n";
                $description_text .= "👇 Выберите " . $this->get_child_level_name($category_id, 'accusative') . ":";
                
                // Отправляем описание
                $this->send_reply($chat_id, $description_text);
                
                // Небольшая задержка перед показом следующего меню
                usleep(300000); // 0.3 секунды
            }
            
            return $this->show_category_selection($chat_id, $category_id, $user_id_telegram);
        }
        
        // Получаем user_id_telegram из callback_query, если не передан
        if (empty($user_id_telegram)) {
            // Пытаемся получить из текущего контекста
            $user_id_telegram = $chat_id; // Временно используем chat_id, если user_id не передан
            if ($log_enabled) {
                error_log('TCM: user_id_telegram was empty, using chat_id: ' . $user_id_telegram);
            }
        }
        
        // Сохраняем выбранную категорию для пользователя (приоритет)
        if (!empty($user_id_telegram)) {
            $user_categories = get_option('tcm_user_categories', array());
            if (!is_array($user_categories)) {
                $user_categories = array();
            }
            // Ограничиваем размер массива (храним только последние 1000 записей)
            if (count($user_categories) > 1000) {
                $user_categories = array_slice($user_categories, -1000, 1000, true);
            }
            $user_categories[$user_id_telegram] = intval($category_id);
            update_option('tcm_user_categories', $user_categories);
            if ($log_enabled) {
                error_log('TCM: Saved category for user: ' . $user_id_telegram . ' -> ' . $category_id);
            }
        }
        
        // Также сохраняем для чата (для совместимости)
        $chat_categories = get_option('tcm_chat_categories', array());
        if (!is_array($chat_categories)) {
            $chat_categories = array();
        }
        // Ограничиваем размер массива (храним только последние 1000 записей)
        if (count($chat_categories) > 1000) {
            $chat_categories = array_slice($chat_categories, -1000, 1000, true);
        }
        // Очищаем массив от возможных циклических ссылок и невалидных значений
        $chat_categories = array_filter($chat_categories, function($value) {
            return is_numeric($value) && $value > 0 && $value < 1000000;
        });
        $chat_categories[(string)$chat_id] = intval($category_id);
        update_option('tcm_chat_categories', $chat_categories);
        if ($log_enabled) {
            error_log('TCM: Saved category for chat: ' . $chat_id . ' -> ' . $category_id);
        }
        
        // Определяем название уровня выбранной категории в разных падежах
        $level_name_nominative = $this->get_category_level_name($category_id, 'nominative');
        $level_name_prepositional = $this->get_category_level_name($category_id, 'prepositional');
        $level_name_accusative = $this->get_category_level_name($category_id, 'accusative');
        
        // Определяем род для правильного склонения глагола
        $gender = 'female'; // По умолчанию женский род
        $category_obj = get_category($category_id);
        if ($category_obj) {
            $level = 0;
            $current = $category_obj;
            while ($current && $current->parent > 0) {
                $level++;
                $current = get_category($current->parent);
                if (!$current) {
                    break;
                }
            }
            if ($level == 0) {
                $gender = 'male'; // Шаг - мужской род
            }
        }
        $selected_verb = ($gender == 'male') ? 'выбран' : 'выбрана';
        
        $text = "✅ <b>" . $level_name_nominative . " " . $selected_verb . "!</b>\n\n" .
                "📂 <b>" . esc_html($category->name) . "</b>\n\n" .
                "💡 <b>Теперь все ваши сообщения будут создаваться в этой " . $level_name_prepositional . ".</b>\n\n" .
                "✍️ <b>Отправьте сообщение боту, чтобы создать запись в этой " . $level_name_prepositional . ".</b>\n\n" .
                "🔄 Выбор сохранен и будет использоваться до тех пор, пока вы не выберете другую " . $level_name_accusative . ".";
        
        // Убрали автоматический запрос к ИИ - теперь пользователь может запросить его через кнопку
        
        // Определяем родительскую категорию для кнопки "Выбрать другую категорию"
        $parent_category_id = 0;
        if ($category->parent > 0) {
            $parent_category_id = $category->parent;
        }
        
        // Определяем callback для кнопки "Выбрать другую категорию"
        // Если есть родительская категория, возвращаемся к ней, иначе к корню
        $back_to_category = $parent_category_id > 0 ? $parent_category_id : 0;
        
        // Проверяем PRO статус для кнопки ИИ ассистента
        $wp_user_id = $this->get_wp_user_id($user_id_telegram);
        $is_pro = false;
        if ($wp_user_id) {
            $is_pro = get_user_meta($wp_user_id, 'tcm_pro_subscription', true);
        }
        
        $keyboard = array();
        
        // Проверяем, есть ли следующая точка для выбранной категории
        $next_point = $this->get_next_point($category_id);
        if ($next_point) {
            $next_point_name_short = mb_strlen($next_point['name']) > 30 ? mb_substr($next_point['name'], 0, 27) . '...' : $next_point['name'];
            $keyboard[] = array(
                array('text' => '➡️ Перейти в следующую точку: ' . $next_point_name_short, 'callback_data' => 'go_to_next_point:' . $next_point['id'])
            );
        }
        
        // Добавляем кнопку ИИ ассистента с учетом PRO статуса
        if ($is_pro) {
            $keyboard[] = array(
                array('text' => '🤖 Получить помощь ИИ', 'callback_data' => 'ai_help:' . $category_id)
            );
        } else {
            $keyboard[] = array(
                array('text' => '⭐ PRO 🤖 Получить помощь ИИ', 'callback_data' => 'ai_help:' . $category_id)
            );
        }
        
        $keyboard[] = array(
            array('text' => '📂 Выбрать другую категорию', 'callback_data' => 'category:' . $back_to_category)
        );
        $keyboard[] = array(
            array('text' => '🏠 Главное меню', 'callback_data' => 'menu')
        );
        
        // Добавляем информацию о текущей точке, если выбрана точка
        $point_id = $this->get_category_at_level($category_id, 2);
        if ($point_id) {
            $point = get_category($point_id);
            if ($point) {
                $point_name_display = mb_strlen($point->name) > 35 ? mb_substr($point->name, 0, 32) . '...' : $point->name;
                $keyboard[] = array(
                    array('text' => '📍 Вы в точке: ' . $point_name_display, 'callback_data' => 'copy_point_name:' . $point_id)
                );
            }
        }
        
        if ($log_enabled) {
            error_log('TCM: Preparing to send confirmation message');
            error_log('TCM: Chat ID: ' . $chat_id);
            error_log('TCM: Category name: ' . $category->name);
            error_log('TCM: Category ID: ' . $category_id);
            error_log('TCM: Message text length: ' . strlen($text));
            error_log('TCM: Keyboard structure: ' . print_r($keyboard, true));
        }
        
        // Отправляем сообщение с клавиатурой
        $result = $this->send_reply_with_keyboard($chat_id, $text, $keyboard);
        
        if ($log_enabled) {
            if (is_wp_error($result)) {
                error_log('TCM: ERROR sending confirmation message: ' . $result->get_error_message());
                error_log('TCM: Error code: ' . $result->get_error_code());
                error_log('TCM: Error data: ' . print_r($result->get_error_data(), true));
            } else {
                error_log('TCM: SUCCESS - Confirmation message sent successfully');
                error_log('TCM: Result: ' . print_r($result, true));
            }
        }
        
        // Если отправка не удалась, пробуем отправить без клавиатуры
        if (is_wp_error($result)) {
            if ($log_enabled) {
                error_log('TCM: Trying to send message without keyboard as fallback');
            }
            $fallback_result = $this->send_reply($chat_id, $text);
            if ($log_enabled) {
                if (is_wp_error($fallback_result)) {
                    error_log('TCM: Fallback also failed: ' . $fallback_result->get_error_message());
                } else {
                    error_log('TCM: Fallback message sent successfully');
                }
            }
            return $fallback_result;
        }
        
        // Промпт больше не выводится при выборе точки
        
        return $result;
    }
    
    /**
     * Показ настроек
     */
    private function show_settings($chat_id, $user_id_telegram) {
        $user = $this->users->get_user_by_telegram_id($user_id_telegram);
        
        $text = "⚙️ <b>Настройки</b>\n\n";
        
        if ($user) {
            $text .= "✅ <b>Статус:</b> Зарегистрирован\n";
            $text .= "👤 <b>Имя:</b> " . esc_html($user->display_name) . "\n";
            $text .= "🔑 <b>Username:</b> @" . esc_html($user->user_login) . "\n\n";
        } else {
            $text .= "❌ <b>Статус:</b> Не зарегистрирован\n\n";
        }
        
        // Получаем выбранную категорию
        $chat_categories = get_option('tcm_chat_categories', array());
        $category_id = isset($chat_categories[$chat_id]) ? $chat_categories[$chat_id] : 0;
        
        if ($category_id > 0) {
            $category = get_category($category_id);
            if ($category) {
                $wp_user_id = $this->get_wp_user_id($user_id_telegram);
                $posts_count = $wp_user_id ? $this->get_category_posts_count($category_id, $wp_user_id) : $this->get_category_posts_count($category_id);
                $category_name = esc_html($category->name);
                if ($posts_count > 0) {
                    $category_name .= ' (' . $posts_count . ')';
                }
                $text .= "📂 <b>Текущая категория:</b> " . $category_name . "\n\n";
            }
        } else {
            $text .= "📂 <b>Категория:</b> Не выбрана\n\n";
        }
        
        // Показываем статус анкеты
        if ($user) {
            $questionnaire_status = $this->get_questionnaire_status($user_id_telegram);
            if ($questionnaire_status['completed']) {
                $text .= "📋 <b>Анкета:</b> Заполнена (" . $questionnaire_status['progress'] . "%)\n\n";
            } else {
                $text .= "📋 <b>Анкета:</b> Не заполнена (" . $questionnaire_status['progress'] . "%)\n\n";
            }
            
            // Показываем настройку времени напоминания
            $wp_user_id = $this->get_wp_user_id($user_id_telegram);
            $reminder_time = get_user_meta($wp_user_id, 'tcm_daily_reminder_time', true);
            if ($reminder_time) {
                $text .= "⏰ <b>Напоминание:</b> " . esc_html($reminder_time) . "\n\n";
            } else {
                $text .= "⏰ <b>Напоминание:</b> Не настроено\n\n";
            }
        }
        
        $keyboard = array();
        
        if (!$user) {
            $keyboard[] = array(
                array('text' => '📝 Регистрация', 'callback_data' => 'register'),
                array('text' => '🔗 Привязка', 'callback_data' => 'link')
            );
        }
        
        if ($user) {
            $keyboard[] = array(
                array('text' => '⏰ Настроить напоминание', 'callback_data' => 'reminder_settings')
            );
        }
        
        $keyboard[] = array(
            array('text' => '📊 Статус', 'callback_data' => 'status'),
            array('text' => '📂 Выбор категории', 'callback_data' => 'category:0')
        );
        
        if ($user) {
            $keyboard[] = array(
                array('text' => '📋 Заполнить анкету', 'callback_data' => 'questionnaire:start')
            );
        }
        
        // Проверяем, есть ли следующая точка для текущей выбранной категории
        $keyboard[] = array(
            array('text' => '🏠 Главное меню', 'callback_data' => 'menu')
        );
        
        if ($category_id > 0) {
            $next_point = $this->get_next_point($category_id);
            if ($next_point) {
                $next_point_name_short = mb_strlen($next_point['name']) > 30 ? mb_substr($next_point['name'], 0, 27) . '...' : $next_point['name'];
                $keyboard[] = array(
                    array('text' => '➡️ Перейти в следующую точку: ' . $next_point_name_short, 'callback_data' => 'go_to_next_point:' . $next_point['id'])
                );
            }
        }
        
        return $this->send_reply_with_keyboard($chat_id, $text, $keyboard);
    }
    
    /**
     * Показ справки
     */
    private function show_help($chat_id) {
        $text = "❓ <b>Справка</b>\n\n" .
                "📝 <b>Как создать запись:</b>\n" .
                "1. Выберите категорию через меню\n" .
                "2. Отправьте сообщение боту\n" .
                "3. Запись будет создана автоматически\n\n" .
                "📋 <b>Команды:</b>\n" .
                "/start или /menu - открыть меню\n" .
                "/register &lt;имя&gt; - регистрация\n" .
                "/link &lt;код&gt; - привязка аккаунта\n" .
                "/status - статус регистрации\n" .
                "/help - показать справку\n\n" .
                "💡 <b>Совет:</b> Используйте меню для удобной навигации.";
        
        $keyboard = array();
        
        // Пытаемся получить user_id_telegram из контекста
        // Если вызывается из callback, user_id_telegram должен быть передан
        // Для упрощения, используем chat_id как fallback
        $user_id_telegram = $chat_id;
        
        $keyboard[] = array(
            array('text' => '🏠 Главное меню', 'callback_data' => 'menu')
        );
        
        // Проверяем, есть ли следующая точка для текущей выбранной категории
        $next_point = $this->get_next_point_for_user($chat_id, $user_id_telegram);
        if ($next_point) {
            $next_point_name_short = mb_strlen($next_point['name']) > 30 ? mb_substr($next_point['name'], 0, 27) . '...' : $next_point['name'];
            $keyboard[] = array(
                array('text' => '➡️ Перейти в следующую точку: ' . $next_point_name_short, 'callback_data' => 'go_to_next_point:' . $next_point['id'])
            );
        }
        
        return $this->send_reply_with_keyboard($chat_id, $text, $keyboard);
    }
    
    /**
     * Показ техподдержки
     */
    private function show_support($chat_id, $user_id_telegram = '') {
        $support_email = get_option('admin_email');
        $site_url = home_url();
        $developer_telegram = '@sshllss';
        
        $text = "💬 <b>Техподдержка</b>\n\n" .
                "Если у вас возникли вопросы или проблемы:\n\n" .
                "👤 <b>Разработчик:</b> " . $developer_telegram . "\n" .
                "📧 <b>Email:</b> " . esc_html($support_email) . "\n" .
                "🌐 <b>Сайт:</b> " . esc_html($site_url) . "\n\n" .
                "Вы можете написать разработчику напрямую или отправить сообщение в службу поддержки через кнопку ниже.";
        
        $keyboard = array(
            array(
                array('text' => '📝 Отправить сообщение в поддержку', 'callback_data' => 'support_send_message')
            )
        );
        
        $keyboard[] = array(
            array('text' => '🏠 Главное меню', 'callback_data' => 'menu')
        );
        
        // Проверяем, есть ли следующая точка для текущей выбранной категории
        if (empty($user_id_telegram)) {
            $user_id_telegram = $chat_id; // Fallback
        }
        $next_point = $this->get_next_point_for_user($chat_id, $user_id_telegram);
        if ($next_point) {
            $next_point_name_short = mb_strlen($next_point['name']) > 30 ? mb_substr($next_point['name'], 0, 27) . '...' : $next_point['name'];
            $keyboard[] = array(
                array('text' => '➡️ Перейти в следующую точку: ' . $next_point_name_short, 'callback_data' => 'go_to_next_point:' . $next_point['id'])
            );
        }
        
        return $this->send_reply_with_keyboard($chat_id, $text, $keyboard);
    }
    
    /**
     * Обработка отправки сообщения в поддержку
     */
    private function handle_support_send_message($chat_id, $user_id_telegram) {
        $wp_user_id = $this->get_wp_user_id($user_id_telegram);
        if (!$wp_user_id) {
            $this->send_reply($chat_id, '❌ Пользователь не найден. Пожалуйста, зарегистрируйтесь через /register');
            return false;
        }
        
        // Сохраняем состояние ожидания сообщения
        update_user_meta($wp_user_id, 'tcm_awaiting_support_message', true);
        
        $text = "📝 <b>Отправка сообщения в поддержку</b>\n\n" .
                "Пожалуйста, напишите ваше сообщение. Оно будет отправлено в службу поддержки со всеми вашими данными.";
        
        $this->send_reply($chat_id, $text);
        return true;
    }
    
    /**
     * Отправка сообщения в службу поддержки
     */
    private function send_support_message($chat_id, $user_id_telegram, $message_text) {
        $wp_user_id = $this->get_wp_user_id($user_id_telegram);
        if (!$wp_user_id) {
            return false;
        }
        
        // Собираем данные пользователя
        $user = get_userdata($wp_user_id);
        $user_name = $user ? $user->display_name : 'Не указано';
        $user_email = $user ? $user->user_email : 'Не указано';
        $user_login = $user ? $user->user_login : 'Не указано';
        
        // Получаем данные из Telegram
        $telegram_username = get_user_meta($wp_user_id, 'tcm_telegram_username', true);
        $telegram_id = get_user_meta($wp_user_id, 'tcm_telegram_id', true);
        $telegram_username_display = $telegram_username ? '@' . $telegram_username : 'Не указан';
        
        // Получаем текущую выбранную категорию
        $current_category_id = $this->get_category_for_chat($chat_id, $user_id_telegram);
        $current_category_name = 'Не выбрана';
        if ($current_category_id) {
            $category = get_category($current_category_id);
            if ($category) {
                $current_category_name = $category->name;
            }
        }
        
        // Проверяем PRO статус
        $payment_class = new TCM_Payment();
        $is_pro = $payment_class->check_pro_subscription($wp_user_id);
        $pro_status = $is_pro ? 'Да' : 'Нет';
        
        // Формируем сообщение для поддержки (без HTML тегов, так как api_to_telegram принимает только текст)
        $support_message = "📨 Новое сообщение из Telegram бота\n\n";
        $support_message .= "👤 Пользователь:\n";
        $support_message .= "• Имя: " . $user_name . "\n";
        $support_message .= "• Логин: " . $user_login . "\n";
        $support_message .= "• Email: " . $user_email . "\n";
        $support_message .= "• ID WordPress: " . $wp_user_id . "\n\n";
        $support_message .= "📱 Telegram:\n";
        $support_message .= "• ID: " . $telegram_id . "\n";
        $support_message .= "• Username: " . $telegram_username_display . "\n";
        $support_message .= "• Chat ID: " . $chat_id . "\n\n";
        $support_message .= "📂 Текущая категория: " . $current_category_name . "\n";
        $support_message .= "⭐ PRO статус: " . $pro_status . "\n\n";
        $support_message .= "💬 Сообщение:\n" . $message_text;
        
        // Отправляем сообщение в поддержку используя тот же токен и chat_id, что и в функции api_to_telegram темы
        // Это гарантирует, что сообщения будут отправляться в тот же чат, куда отправляются сообщения с сайта
        $support_telegram_token = '7869572806:AAFMqgkrodvf6yhhKrOH6frSI_d4-7P2AZY'; // Токен из функции api_to_telegram темы
        $support_chat_id = '661000215'; // ID чата поддержки из функции api_to_telegram темы
        
        $url = "https://api.telegram.org/bot{$support_telegram_token}/sendMessage";
        $response = wp_remote_post($url, array(
            'body' => array(
                'chat_id' => $support_chat_id,
                'text' => $support_message
            )
        ));
        
        if (is_wp_error($response)) {
            $this->send_reply($chat_id, '❌ Ошибка при отправке сообщения. Попробуйте позже или напишите разработчику напрямую: @sshllss');
            return false;
        }
        
        // Проверяем ответ API
        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            $response_body = wp_remote_retrieve_body($response);
            $log_enabled = defined('WP_DEBUG') && WP_DEBUG;
            if ($log_enabled) {
                error_log('TCM: Support message send error. Code: ' . $response_code . ', Body: ' . $response_body);
            }
            $this->send_reply($chat_id, '❌ Ошибка при отправке сообщения. Попробуйте позже или напишите разработчику напрямую: @sshllss');
            return false;
        }
        
        // Удаляем состояние ожидания
        delete_user_meta($wp_user_id, 'tcm_awaiting_support_message');
        
        $this->send_reply($chat_id, "✅ <b>Сообщение отправлено в службу поддержки!</b>\n\nМы обязательно ответим вам в ближайшее время.");
        
        return true;
    }
    
    /**
     * Показ информации о регистрации
     */
    private function show_register_info($chat_id) {
        $text = "📝 <b>Регистрация</b>\n\n" .
                "Для регистрации отправьте команду:\n" .
                "/register &lt;ваше имя&gt;\n\n" .
                "Пример:\n" .
                "/register Иван Иванов\n\n" .
                "После регистрации вы сможете создавать записи на сайте.";
        
        $keyboard = array(
            array(
                array('text' => '⚙️ Настройки', 'callback_data' => 'settings'),
                array('text' => '🏠 Главное меню', 'callback_data' => 'menu')
            )
        );
        
        return $this->send_reply_with_keyboard($chat_id, $text, $keyboard);
    }
    
    /**
     * Показ информации о привязке
     */
    private function show_link_info($chat_id) {
        $text = "🔗 <b>Привязка аккаунта</b>\n\n" .
                "Для привязки Telegram к существующему аккаунту:\n\n" .
                "1. Войдите в админ-панель WordPress\n" .
                "2. Перейдите в Telegram Manager → Пользователи Telegram\n" .
                "3. Получите код верификации\n" .
                "4. Отправьте команду:\n" .
                "/link &lt;код&gt;\n\n" .
                "Пример:\n" .
                "/link ABC123";
        
        $keyboard = array(
            array(
                array('text' => '⚙️ Настройки', 'callback_data' => 'settings'),
                array('text' => '🏠 Главное меню', 'callback_data' => 'menu')
            )
        );
        
        return $this->send_reply_with_keyboard($chat_id, $text, $keyboard);
    }
    
    /**
     * Отправка сообщения с клавиатурой
     */
    private function send_reply_with_keyboard($chat_id, $text, $keyboard) {
        $log_enabled = defined('WP_DEBUG') && WP_DEBUG;
        $token = get_option('tcm_telegram_token', '');
        
        if (empty($token)) {
            if ($log_enabled) {
                error_log('TCM: send_reply_with_keyboard - Token is empty');
            }
            return new WP_Error('tcm_no_token', 'Telegram токен не настроен');
        }
        
        $url = "https://api.telegram.org/bot{$token}/sendMessage";
        
        $reply_markup = json_encode(array(
            'inline_keyboard' => $keyboard
        ));
        
        if ($log_enabled) {
            error_log('TCM: send_reply_with_keyboard - Chat ID: ' . $chat_id);
            error_log('TCM: send_reply_with_keyboard - Text length: ' . strlen($text));
            error_log('TCM: send_reply_with_keyboard - Keyboard: ' . $reply_markup);
        }
        
        $body = array(
            'chat_id' => $chat_id,
            'text' => $text,
            'parse_mode' => 'HTML',
            'reply_markup' => $reply_markup
        );
        
        $args = array(
            'body' => $body,
            'timeout' => 30
        );
        
        $response = wp_remote_post($url, $args);
        
        if (is_wp_error($response)) {
            if ($log_enabled) {
                error_log('TCM: send_reply_with_keyboard - WP_Error: ' . $response->get_error_message());
            }
            return $response;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = json_decode(wp_remote_retrieve_body($response), true);
        
        if ($log_enabled) {
            error_log('TCM: send_reply_with_keyboard - Response code: ' . $response_code);
            error_log('TCM: send_reply_with_keyboard - Response body: ' . print_r($response_body, true));
        }
        
        if (isset($response_body['ok']) && $response_body['ok'] === true) {
            if ($log_enabled) {
                error_log('TCM: send_reply_with_keyboard - Message sent successfully');
            }
            return $response_body;
        }
        
        $error_msg = isset($response_body['description']) ? $response_body['description'] : 'Ошибка отправки в Telegram';
        if ($log_enabled) {
            error_log('TCM: send_reply_with_keyboard - Telegram API error: ' . $error_msg);
        }
        
        return new WP_Error('tcm_telegram_error', $error_msg);
    }
    
    /**
     * Получение структуры вопросов анкеты
     */
    private function get_questionnaire_structure() {
        return array(
            'section1' => array(
                'title' => 'Раздел 1. Демография и базовые данные',
                'questions' => array(
                    'program_type' => array(
                        'text' => 'По какой программе вы работаете?',
                        'type' => 'choice',
                        'options' => array('Анонимные Наркоманы (АН)', 'Анонимные Алкоголики (АА)', 'Другая программа 12 шагов', 'Не работаю по программе')
                    ),
                    'birth_date' => array(
                        'text' => 'Дата Рождения',
                        'type' => 'date',
                        'hint' => 'Укажите дату рождения в формате ДД.ММ.ГГГГ (например: 15.05.1990)'
                    ),
                    'gender' => array(
                        'text' => 'Пол',
                        'type' => 'choice',
                        'options' => array('Мужской', 'Женский', 'Другое', 'Не указывать')
                    ),
                    'city' => array(
                        'text' => 'Город/регион проживания',
                        'type' => 'text',
                        'hint' => 'Укажите город или регион, где вы проживаете'
                    ),
                    'education' => array(
                        'text' => 'Образование',
                        'type' => 'choice',
                        'options' => array('Среднее', 'Среднее специальное', 'Высшее', 'Неоконченное высшее', 'Другое')
                    ),
                    'occupation' => array(
                        'text' => 'Род занятий',
                        'type' => 'choice',
                        'options' => array('Работаю', 'Учусь', 'Не работаю', 'На пенсии', 'Другое')
                    )
                )
            ),
            'section2' => array(
                'title' => 'Раздел 2. Зависимость: история и статус',
                'questions' => array(
                    'addiction_type' => array(
                        'text' => 'Основной вид зависимости',
                        'type' => 'multiple',
                        'options' => array('Алкоголь', 'Никотин', 'Наркотики', 'Игровая/гэмблинг', 'Пищевая', 'Интернет и соцсети', 'Другое')
                    ),
                    'last_use_date' => array(
                        'text' => 'Дата последнего употребления/срыва',
                        'type' => 'text',
                        'hint' => 'Формат: ДД.ММ.ГГГГ или "сегодня", "вчера", "неделю назад"'
                    ),
                    'addiction_years' => array(
                        'text' => 'Стаж зависимости (лет/месяцев)',
                        'type' => 'text',
                        'hint' => 'Например: 5 лет, 2 года 3 месяца'
                    ),
                    'use_form' => array(
                        'text' => 'Форма употребления',
                        'type' => 'choice',
                        'options' => array('Эпизодическая', 'Регулярная', 'Запойная', 'Не применимо')
                    ),
                    'average_dose' => array(
                        'text' => 'Средняя доза/частота (в период активного употребления)',
                        'type' => 'text',
                        'hint' => 'Опишите кратко'
                    ),
                    'triggers' => array(
                        'text' => 'Причины, которые чаще всего приводят к срыву (триггеры)',
                        'type' => 'multiple',
                        'options' => array('Стресс', 'Одиночество', 'Скука', 'Конфликты', 'Праздники', 'Другое')
                    ),
                    'previous_attempts' => array(
                        'text' => 'Предыдущие попытки бросить (количество)',
                        'type' => 'text',
                        'hint' => 'Укажите число попыток'
                    ),
                    'longest_remission' => array(
                        'text' => 'Длительность самой длительной ремиссии',
                        'type' => 'text',
                        'hint' => 'Например: 6 месяцев, 1 год'
                    ),
                    'tried_methods' => array(
                        'text' => 'Методы, которые пробовал для лечения',
                        'type' => 'multiple',
                        'options' => array('АА/АН', 'Реабилитационный центр', 'Кодирование', 'Психотерапия', 'Медикаменты', 'Самолечение')
                    )
                )
            ),
            'section3' => array(
                'title' => 'Раздел 3. Физическое и психическое здоровье',
                'questions' => array(
                    'chronic_diseases' => array(
                        'text' => 'Наличие диагностированных хронических заболеваний',
                        'type' => 'multiple',
                        'options' => array('Печень', 'Сердце', 'ЖКТ', 'Психические расстройства', 'Нет', 'Другое')
                    ),
                    'medications' => array(
                        'text' => 'Принимаемые постоянно лекарства',
                        'type' => 'text',
                        'hint' => 'Перечислите, если есть, или напишите "нет"'
                    ),
                    'withdrawal_syndrome' => array(
                        'text' => 'Наличие абстинентного синдрома ("ломки") при отмене',
                        'type' => 'choice',
                        'options' => array('Да', 'Нет', 'Иногда')
                    ),
                    'withdrawal_strength' => array(
                        'text' => 'Сила абстинентного синдрома (по шкале 1-10)',
                        'type' => 'choice',
                        'options' => array('1', '2', '3', '4', '5', '6', '7', '8', '9', '10', 'Не применимо')
                    ),
                    'current_symptoms' => array(
                        'text' => 'Текущие симптомы, которые беспокоят',
                        'type' => 'multiple',
                        'options' => array('Бессонница', 'Тревога', 'Панические атаки', 'Депрессия', 'Апатия', 'Суицидальные мысли', 'Нет')
                    ),
                    'past_trauma' => array(
                        'text' => 'Наличие в прошлом ЧМТ, травм, операций',
                        'type' => 'text',
                        'hint' => 'Опишите кратко или напишите "нет"'
                    )
                )
            ),
            'section4' => array(
                'title' => 'Раздел 4. Психологический портрет и мотивация',
                'questions' => array(
                    'main_reason' => array(
                        'text' => 'Основная причина, по которой хочу избавиться от зависимости',
                        'type' => 'multiple',
                        'options' => array('Здоровье', 'Семья', 'Карьера', 'Закон', 'Самоуважение', 'Другое')
                    ),
                    'motivation_level' => array(
                        'text' => 'Уровень мотивации к выздоровлению (по шкале от 1 до 10)',
                        'type' => 'choice',
                        'options' => array('1', '2', '3', '4', '5', '6', '7', '8', '9', '10')
                    ),
                    'strengths' => array(
                        'text' => 'Сильные стороны личности',
                        'type' => 'multiple',
                        'options' => array('Целеустремленность', 'Креативность', 'Общительность', 'Дисциплина', 'Другое')
                    ),
                    'weaknesses' => array(
                        'text' => '"Слабости" или зоны роста',
                        'type' => 'multiple',
                        'options' => array('Низкая самооценка', 'Импульсивность', 'Неумение говорить "нет"', 'Перфекционизм', 'Другое')
                    ),
                    'coping_skills' => array(
                        'text' => 'Навыки совладания со стрессом (копинги), которыми владею',
                        'type' => 'multiple',
                        'options' => array('Дыхательные техники', 'Спорт', 'Хобби', 'Разговор с другом', 'Ничего')
                    ),
                    'belief_system' => array(
                        'text' => 'Система убеждений/религия',
                        'type' => 'choice',
                        'options' => array('12 шагов', 'Светские группы', 'Религиозные общины', 'Атеизм', 'Другое', 'Не определился')
                    )
                )
            ),
            'section5' => array(
                'title' => 'Раздел 5. Социальное окружение и ресурсы',
                'questions' => array(
                    'family_status' => array(
                        'text' => 'Семейное положение и отношения в семье',
                        'type' => 'choice',
                        'options' => array('Поддерживающая семья', 'Конфликтная семья', 'Созависимая семья', 'Один/одна', 'Другое')
                    ),
                    'sober_friends' => array(
                        'text' => 'Есть ли друзья/знакомые, не употребляющие ПАВ',
                        'type' => 'choice',
                        'options' => array('Да, много', 'Да, несколько', 'Нет', 'Не знаю')
                    ),
                    'living_with_users' => array(
                        'text' => 'Живет ли с людьми, которые употребляют',
                        'type' => 'choice',
                        'options' => array('Да', 'Нет', 'Иногда')
                    ),
                    'financial_status' => array(
                        'text' => 'Финансовое положение и стабильность',
                        'type' => 'choice',
                        'options' => array('Стабильное', 'Нестабильное', 'Сложное')
                    ),
                    'free_time' => array(
                        'text' => 'Наличие свободного времени (график работы)',
                        'type' => 'text',
                        'hint' => 'Опишите ваш график'
                    ),
                    'internet_access' => array(
                        'text' => 'Доступ к интернету и смартфону',
                        'type' => 'choice',
                        'options' => array('Да, постоянный', 'Да, ограниченный', 'Нет')
                    )
                )
            ),
            'section6' => array(
                'title' => 'Раздел 6. Интересы и образ жизни',
                'questions' => array(
                    'hobbies' => array(
                        'text' => 'Хобби и интересы (нынешние или прошлые, до зависимости)',
                        'type' => 'text',
                        'hint' => 'Перечислите ваши увлечения'
                    ),
                    'sport_attitude' => array(
                        'text' => 'Отношение к спорту и физической активности',
                        'type' => 'choice',
                        'options' => array('Люблю, занимаюсь регулярно', 'Люблю, но не занимаюсь', 'Нейтрально', 'Не люблю')
                    ),
                    'sport_types' => array(
                        'text' => 'Какие виды спорта предпочитаете',
                        'type' => 'text',
                        'hint' => 'Перечислите или напишите "нет"'
                    ),
                    'info_preference' => array(
                        'text' => 'Предпочтения в информации (как лучше усваиваю)',
                        'type' => 'multiple',
                        'options' => array('Книги', 'Аудио/подкасты', 'Видео', 'Интерактивные курсы', 'Личное общение')
                    ),
                    'support_format' => array(
                        'text' => 'Комфортный формат поддержки',
                        'type' => 'multiple',
                        'options' => array('Индивидуальный', 'Групповой', 'Анонимный онлайн', 'Очный')
                    )
                )
            ),
            'section7' => array(
                'title' => 'Раздел 7. Цели и ожидания',
                'questions' => array(
                    'main_goal' => array(
                        'text' => 'Главная цель на ближайший месяц',
                        'type' => 'multiple',
                        'options' => array('Не пить/не употреблять', 'Наладить сон', 'Пойти к врачу', 'Найти группу', 'Справиться с тягой', 'Другое')
                    ),
                    'expectations' => array(
                        'text' => 'Чего жду от системы рекомендаций',
                        'type' => 'multiple',
                        'options' => array('Конкретные советы "что делать сейчас"', 'Информацию', 'Поддержку', 'План на день', 'Истории успеха')
                    )
                )
            )
        );
    }
    
    /**
     * Получение статуса заполнения анкеты
     */
    private function get_questionnaire_status($user_telegram_id) {
        $wp_user_id = $this->get_wp_user_id($user_telegram_id);
        if (!$wp_user_id) {
            return array('completed' => false, 'progress' => 0, 'current_section' => null, 'consent_given' => false);
        }
        
        $consent_given = get_user_meta($wp_user_id, 'tcm_data_collection_consent', true);
        if (!$consent_given) {
            return array('completed' => false, 'progress' => 0, 'current_section' => null, 'consent_given' => false);
        }
        
        $answers = get_user_meta($wp_user_id, 'tcm_questionnaire_answers', true);
        if (empty($answers) || !is_array($answers)) {
            return array('completed' => false, 'progress' => 0, 'current_section' => null, 'consent_given' => true);
        }
        
        $structure = $this->get_questionnaire_structure();
        $total_questions = 0;
        $answered_questions = 0;
        
        foreach ($structure as $section_key => $section) {
            foreach ($section['questions'] as $question_key => $question) {
                $total_questions++;
                if (isset($answers[$section_key][$question_key]) && !empty($answers[$section_key][$question_key])) {
                    $answered_questions++;
                }
            }
        }
        
        $progress = $total_questions > 0 ? round(($answered_questions / $total_questions) * 100) : 0;
        $completed = $progress >= 80; // Считаем заполненной, если ответили на 80% вопросов
        
        return array(
            'completed' => $completed,
            'progress' => $progress,
            'answered' => $answered_questions,
            'total' => $total_questions,
            'current_section' => null,
            'consent_given' => true
        );
    }
    
    /**
     * Проверка и запрос разрешения на сбор данных
     */
    private function request_data_collection_consent($chat_id, $user_id_telegram, $wp_user_id) {
        $consent_given = get_user_meta($wp_user_id, 'tcm_data_collection_consent', true);
        if ($consent_given) {
            return true; // Разрешение уже дано
        }
        
        $message = "📋 <b>Сбор данных для персонализации</b>\n\n" .
                   "Для того чтобы мы могли предложить вам более точные рекомендации и поддержку, нам необходимо собрать некоторую информацию о вашей ситуации.\n\n" .
                   "Мы будем задавать вопросы постепенно, после каждого вашего сообщения. Вы можете пропустить любой вопрос или заполнить анкету позже.\n\n" .
                   "Все данные хранятся конфиденциально и используются только для предоставления рекомендаций.\n\n" .
                   "Даете ли вы согласие на сбор данных о зависимости?";
        
        $keyboard = array(
            array(
                array('text' => '✅ Да, согласен', 'callback_data' => 'consent:yes'),
                array('text' => '❌ Нет, не согласен', 'callback_data' => 'consent:no')
            )
        );
        
        $this->send_reply_with_keyboard($chat_id, $message, $keyboard);
        return false;
    }
    
    /**
     * Показ следующего неотвеченного вопроса анкеты
     */
    private function show_next_questionnaire_question($chat_id, $user_id_telegram, $wp_user_id) {
        $log_enabled = defined('WP_DEBUG') && WP_DEBUG;
        
        if ($log_enabled) {
            error_log('TCM: show_next_questionnaire_question called. Chat ID: ' . $chat_id . ', User ID: ' . $user_id_telegram . ', WP User ID: ' . $wp_user_id);
        }
        
        // Проверяем разрешение
        $consent_given = get_user_meta($wp_user_id, 'tcm_data_collection_consent', true);
        if ($log_enabled) {
            error_log('TCM: Consent given: ' . ($consent_given ? 'yes' : 'no'));
        }
        
        if (!$consent_given) {
            if ($log_enabled) {
                error_log('TCM: Requesting consent');
            }
            return $this->request_data_collection_consent($chat_id, $user_id_telegram, $wp_user_id);
        }
        
        // Получаем следующий неотвеченный вопрос
        $next_question = $this->get_next_unanswered_question($wp_user_id);
        
        if ($log_enabled) {
            error_log('TCM: Next question: ' . ($next_question ? 'found' : 'not found'));
            if ($next_question) {
                error_log('TCM: Question details: ' . print_r($next_question, true));
            }
        }
        
        if (!$next_question) {
            // Все вопросы отвечены
            $status = $this->get_questionnaire_status($user_id_telegram);
            if ($log_enabled) {
                error_log('TCM: No next question. Status: ' . print_r($status, true));
            }
            if ($status['completed']) {
                // Можно показать сообщение о завершении, но не каждый раз
                return true;
            }
            return true;
        }
        
        // Формируем вопрос
        $question = $next_question['question'];
        $section = $next_question['section'];
        $question_key = $next_question['question_key'];
        $section_key = $next_question['section_key'];
        $question_num = $next_question['question_num'];
        
        $text = "📋 <b>Вопрос для анкеты</b>\n\n";
        $text .= "<b>" . $question['text'] . "</b>\n\n";
        
        // Получаем текущие ответы для отображения выбранных вариантов
        $answers = get_user_meta($wp_user_id, 'tcm_questionnaire_answers', true);
        $current_answer = isset($answers[$section_key][$question_key]) ? $answers[$section_key][$question_key] : null;
        
        // Показываем варианты ответа в виде кнопок
        if (isset($question['options']) && is_array($question['options'])) {
            if ($question['type'] === 'multiple') {
                $text .= "💡 Вы можете выбрать несколько вариантов, нажимая на кнопки\n\n";
                // Показываем уже выбранные варианты
                if (is_array($current_answer) && !empty($current_answer)) {
                    $text .= "✅ <b>Выбрано:</b> " . implode(", ", $current_answer) . "\n\n";
                }
            } else {
                $text .= "💡 Выберите один вариант из списка\n\n";
                // Показываем текущий ответ, если есть
                if ($current_answer && !is_array($current_answer)) {
                    $text .= "✅ <b>Текущий ответ:</b> " . $current_answer . "\n\n";
                }
            }
        } else {
            if (isset($question['hint'])) {
                $text .= "💡 " . $question['hint'] . "\n\n";
            } else {
                $text .= "💡 Введите ваш ответ текстом\n\n";
            }
        }
        
        // Проверяем PRO статус для кнопки ИИ ассистента
        $is_pro = get_user_meta($wp_user_id, 'tcm_pro_subscription', true);
        
        // Создаем клавиатуру с кнопками вариантов ответов
        $keyboard = array();
        
        // Добавляем кнопки для вариантов ответов, если они есть
        if (isset($question['options']) && is_array($question['options'])) {
            $option_index = 0;
            $row = array();
            
            foreach ($question['options'] as $option) {
                // Определяем, выбран ли этот вариант
                $is_selected = false;
                if ($question['type'] === 'multiple' && is_array($current_answer)) {
                    $is_selected = in_array($option, $current_answer);
                } elseif ($question['type'] === 'choice' && $current_answer === $option) {
                    $is_selected = true;
                }
                
                // Добавляем отметку, если вариант выбран
                $button_text = $is_selected ? "✅ " . $option : $option;
                
                $row[] = array(
                    'text' => $button_text,
                    'callback_data' => 'select_option:' . $section_key . ':' . $question_key . ':' . $option_index
                );
                
                // Размещаем по 2 кнопки в ряд
                if (count($row) == 2) {
                    $keyboard[] = $row;
                    $row = array();
                }
                
                $option_index++;
            }
            
            // Добавляем оставшиеся кнопки
            if (!empty($row)) {
                $keyboard[] = $row;
            }
            
            // Добавляем кнопку "Свой вариант" в конце
            $keyboard[] = array(
                array('text' => '✏️ Свой вариант', 'callback_data' => 'questionnaire:custom:' . $section_key . ':' . $question_key)
            );
        }
        
        // Добавляем служебные кнопки
        $service_row = array(
            array('text' => '⏭️ Пропустить', 'callback_data' => 'skip_question:' . $section_key . ':' . $question_key)
        );
        
        // Добавляем кнопку ИИ ассистента с учетом PRO статуса
        if ($is_pro) {
            $service_row[] = array('text' => '🤖 ИИ ассистент', 'callback_data' => 'ai_assistant:' . $section_key . ':' . $question_key);
        } else {
            $service_row[] = array('text' => '⭐ PRO 🤖 ИИ ассистент', 'callback_data' => 'ai_assistant:' . $section_key . ':' . $question_key);
        }
        
        $keyboard[] = $service_row;
        
        // Сохраняем текущий вопрос для обработки ответа
        update_user_meta($wp_user_id, 'tcm_questionnaire_current_question', array(
            'section_key' => $section_key,
            'question_key' => $question_key,
            'question_num' => $question_num
        ));
        
        $log_enabled = defined('WP_DEBUG') && WP_DEBUG;
        
        if ($log_enabled) {
            error_log('TCM: Sending question with keyboard. Chat ID: ' . $chat_id . ', Question: ' . $question['text']);
            error_log('TCM: Keyboard structure: ' . print_r($keyboard, true));
        }
        
        $result = $this->send_reply_with_keyboard($chat_id, $text, $keyboard);
        
        if ($log_enabled) {
            if (is_wp_error($result)) {
                error_log('TCM: Error sending question: ' . $result->get_error_message());
            } else {
                error_log('TCM: Question sent successfully');
            }
        }
        
        return true;
    }
    
    /**
     * Показ заполнения одного поля анкеты после отправки записи
     */
    private function show_one_questionnaire_question_after_post($chat_id, $user_id_telegram, $wp_user_id) {
        $log_enabled = defined('WP_DEBUG') && WP_DEBUG;
        
        if ($log_enabled) {
            error_log('TCM: show_one_questionnaire_question_after_post called. Chat ID: ' . $chat_id . ', User ID: ' . $user_id_telegram . ', WP User ID: ' . $wp_user_id);
        }
        
        // Проверяем разрешение
        $consent_given = get_user_meta($wp_user_id, 'tcm_data_collection_consent', true);
        if ($log_enabled) {
            error_log('TCM: Consent given: ' . ($consent_given ? 'yes' : 'no'));
        }
        
        if (!$consent_given) {
            if ($log_enabled) {
                error_log('TCM: Requesting consent');
            }
            return $this->request_data_collection_consent($chat_id, $user_id_telegram, $wp_user_id);
        }
        
        // Получаем следующий неотвеченный вопрос (разрешаем повтор последнего, чтобы напоминать после точки)
        $next_question = $this->get_next_unanswered_question($wp_user_id, true);
        
        if ($log_enabled) {
            error_log('TCM: Next question: ' . ($next_question ? 'found' : 'not found'));
            if ($next_question) {
                error_log('TCM: Question details: ' . print_r($next_question, true));
            }
        }
        
        if (!$next_question) {
            // Все вопросы отвечены - не показываем ничего после записи
            if ($log_enabled) {
                error_log('TCM: No unanswered questions, skipping questionnaire after post');
            }
            return true;
        }
        
        // Формируем вопрос
        $question = $next_question['question'];
        $section = $next_question['section'];
        $question_key = $next_question['question_key'];
        $section_key = $next_question['section_key'];
        $question_num = $next_question['question_num'];
        
        $text = "📋 <b>Заполнение анкеты</b>\n\n";
        $text .= "Помогите нам лучше понять вашу ситуацию, ответив на один вопрос:\n\n";
        $text .= "<b>" . $question['text'] . "</b>\n\n";
        
        // Получаем текущие ответы для отображения выбранных вариантов
        $answers = get_user_meta($wp_user_id, 'tcm_questionnaire_answers', true);
        $current_answer = isset($answers[$section_key][$question_key]) ? $answers[$section_key][$question_key] : null;
        
        // Показываем варианты ответа в виде кнопок
        if (isset($question['options']) && is_array($question['options'])) {
            if ($question['type'] === 'multiple') {
                $text .= "💡 Вы можете выбрать несколько вариантов, нажимая на кнопки\n\n";
                // Показываем уже выбранные варианты
                if (is_array($current_answer) && !empty($current_answer)) {
                    $text .= "✅ <b>Выбрано:</b> " . implode(", ", $current_answer) . "\n\n";
                }
            } else {
                $text .= "💡 Выберите один вариант из списка\n\n";
                // Показываем текущий ответ, если есть
                if ($current_answer && !is_array($current_answer)) {
                    $text .= "✅ <b>Текущий ответ:</b> " . $current_answer . "\n\n";
                }
            }
        } else {
            if (isset($question['hint'])) {
                $text .= "💡 " . $question['hint'] . "\n\n";
            } else {
                $text .= "💡 Введите ваш ответ текстом\n\n";
            }
        }
        
        // Создаем клавиатуру с кнопками вариантов ответов
        $keyboard = array();
        
        // Добавляем кнопки для вариантов ответов, если они есть
        if (isset($question['options']) && is_array($question['options'])) {
            $option_index = 0;
            $row = array();
            
            foreach ($question['options'] as $option) {
                // Определяем, выбран ли этот вариант
                $is_selected = false;
                if ($question['type'] === 'multiple' && is_array($current_answer)) {
                    $is_selected = in_array($option, $current_answer);
                } elseif ($question['type'] === 'choice' && $current_answer === $option) {
                    $is_selected = true;
                }
                
                // Добавляем отметку, если вариант выбран
                $button_text = $is_selected ? "✅ " . $option : $option;
                
                $row[] = array(
                    'text' => $button_text,
                    'callback_data' => 'select_option:' . $section_key . ':' . $question_key . ':' . $option_index
                );
                
                // Размещаем по 2 кнопки в ряд
                if (count($row) == 2) {
                    $keyboard[] = $row;
                    $row = array();
                }
                
                $option_index++;
            }
            
            // Добавляем оставшиеся кнопки
            if (!empty($row)) {
                $keyboard[] = $row;
            }
            
            // Добавляем кнопку "Свой вариант" в конце
            $keyboard[] = array(
                array('text' => '✏️ Свой вариант', 'callback_data' => 'questionnaire:custom:' . $section_key . ':' . $question_key)
            );
        }
        
        // Добавляем служебные кнопки
        $keyboard[] = array(
            array('text' => '⏭️ Пропустить', 'callback_data' => 'skip_question:' . $section_key . ':' . $question_key)
        );
        
        // Сохраняем текущий вопрос для обработки ответа
        update_user_meta($wp_user_id, 'tcm_questionnaire_current_question', array(
            'section_key' => $section_key,
            'question_key' => $question_key,
            'question_num' => $question_num
        ));
        
        if ($log_enabled) {
            error_log('TCM: Sending questionnaire question after post. Chat ID: ' . $chat_id . ', Question: ' . $question['text']);
            error_log('TCM: Keyboard structure: ' . print_r($keyboard, true));
        }
        
        $result = $this->send_reply_with_keyboard($chat_id, $text, $keyboard);
        
        if ($log_enabled) {
            if (is_wp_error($result)) {
                error_log('TCM: Error sending question: ' . $result->get_error_message());
            } else {
                error_log('TCM: Question sent successfully');
            }
        }
        
        return true;
    }
    
    /**
     * Получение следующего неотвеченного вопроса
     */
    private function get_next_unanswered_question($wp_user_id, $allow_repeat_last = false) {
        $log_enabled = defined('WP_DEBUG') && WP_DEBUG;
        
        $answers = get_user_meta($wp_user_id, 'tcm_questionnaire_answers', true);
        if (!is_array($answers)) {
            $answers = array();
        }
        
        // Получаем историю показанных вопросов (чтобы не показывать один и тот же)
        $shown_questions = get_user_meta($wp_user_id, 'tcm_questionnaire_shown_questions', true);
        if (!is_array($shown_questions)) {
            $shown_questions = array();
        }
        
        $structure = $this->get_questionnaire_structure();
        $total_questions = 0;
        
        // Считаем общее количество вопросов
        foreach ($structure as $section) {
            $total_questions += count($section['questions']);
        }
        
        if ($log_enabled) {
            error_log('TCM: get_next_unanswered_question - Total questions: ' . $total_questions);
            error_log('TCM: Last shown: ' . (isset($shown_questions['last_shown']) ? $shown_questions['last_shown'] : 'none'));
        }
        
        // Ищем первый неотвеченный вопрос, который еще не показывали в последний раз
        $last_shown = isset($shown_questions['last_shown']) ? $shown_questions['last_shown'] : null;
        // Если разрешено повторять последний, сбрасываем фильтр
        if ($allow_repeat_last) {
            $last_shown = null;
        }
        $found_after_last = $allow_repeat_last;
        $question_counter = 0;
        
        // Проверяем, существует ли last_shown в текущей структуре
        $last_shown_exists = false;
        if ($last_shown !== null) {
            foreach ($structure as $section_key => $section) {
                foreach ($section['questions'] as $question_key => $question) {
                    $question_id = $section_key . ':' . $question_key;
                    if ($question_id === $last_shown) {
                        $last_shown_exists = true;
                        break 2;
                    }
                }
            }
        }
        
        // Если last_shown не существует в структуре (например, поле было переименовано), сбрасываем его
        if ($last_shown !== null && !$last_shown_exists) {
            if ($log_enabled) {
                error_log('TCM: Last shown question (' . $last_shown . ') not found in structure, resetting');
            }
            $last_shown = null;
            $shown_questions['last_shown'] = null;
            update_user_meta($wp_user_id, 'tcm_questionnaire_shown_questions', $shown_questions);
        }
        
        // Получаем список пропущенных вопросов
        $skipped_questions = isset($shown_questions['skipped']) ? $shown_questions['skipped'] : array();
        if (!is_array($skipped_questions)) {
            $skipped_questions = array();
        }
        
        // Сначала проверяем вопрос про программу - он должен быть заполнен первым
        $program_question_id = 'section1:program_type';
        $program_answered = isset($answers['section1']['program_type']) && 
                           !empty($answers['section1']['program_type']);
        
        // Вопрос про программу показывается снова, даже если его пропустили (игнорируем факт пропуска)
        // Если вопрос про программу не заполнен, возвращаем его первым
        if (!$program_answered) {
            if (isset($structure['section1']['questions']['program_type'])) {
                $program_question = $structure['section1']['questions']['program_type'];
                if ($log_enabled) {
                    error_log('TCM: Returning program_type question as first (not answered yet)');
                }
                
                // Обновляем историю показанных вопросов
                $shown_questions['last_shown'] = $program_question_id;
                update_user_meta($wp_user_id, 'tcm_questionnaire_shown_questions', $shown_questions);
                
                // Считаем количество отвеченных вопросов
                $answered_count = 0;
                foreach ($structure as $s_key => $s) {
                    foreach ($s['questions'] as $q_key => $q) {
                        if (isset($answers[$s_key][$q_key]) && !empty($answers[$s_key][$q_key])) {
                            $answered_count++;
                        }
                    }
                }
                
                return array(
                    'section' => $structure['section1'],
                    'section_key' => 'section1',
                    'question' => $program_question,
                    'question_key' => 'program_type',
                    'question_num' => $answered_count + 1,
                    'total' => $total_questions
                );
            }
        }
        
        foreach ($structure as $section_key => $section) {
            foreach ($section['questions'] as $question_key => $question) {
                $question_counter++;
                $question_id = $section_key . ':' . $question_key;
                
                // Проверяем, отвечен ли вопрос
                $is_answered = isset($answers[$section_key][$question_key]) && 
                               !empty($answers[$section_key][$question_key]);
                
                // Проверяем, пропущен ли вопрос
                // ИСКЛЮЧЕНИЕ: вопрос про программу игнорируем в списке пропущенных
                $program_question_id = 'section1:program_type';
                $is_skipped = false;
                if ($question_id !== $program_question_id) {
                    $is_skipped = in_array($question_id, $skipped_questions);
                }
                
                if ($log_enabled && $question_counter <= 3) {
                    error_log('TCM: Question ' . $question_counter . ' (' . $question_id . '): answered=' . ($is_answered ? 'yes' : 'no') . ', skipped=' . ($is_skipped ? 'yes' : 'no'));
                }
                
                if (!$is_answered && !$is_skipped) {
                    // Если это последний показанный вопрос, пропускаем его
                    if ($last_shown === $question_id) {
                        $found_after_last = true;
                        if ($log_enabled) {
                            error_log('TCM: Found last shown question, skipping it');
                        }
                        continue;
                    }
                    
                    // Если мы уже прошли последний показанный вопрос, показываем этот
                    if ($found_after_last || $last_shown === null) {
                        // Обновляем историю показанных вопросов
                        $shown_questions['last_shown'] = $question_id;
                        update_user_meta($wp_user_id, 'tcm_questionnaire_shown_questions', $shown_questions);
                        
                        // Считаем количество отвеченных вопросов для правильного номера
                        $answered_count = 0;
                        foreach ($structure as $s_key => $s) {
                            foreach ($s['questions'] as $q_key => $q) {
                                if (isset($answers[$s_key][$q_key]) && !empty($answers[$s_key][$q_key])) {
                                    $answered_count++;
                                }
                            }
                        }
                        
                        if ($log_enabled) {
                            error_log('TCM: Found next question: ' . $question_id . ', answered: ' . $answered_count . '/' . $total_questions);
                        }
                        
                        return array(
                            'section' => $section,
                            'section_key' => $section_key,
                            'question' => $question,
                            'question_key' => $question_key,
                            'question_num' => $answered_count + 1,
                            'total' => $total_questions
                        );
                    }
                }
            }
        }
        
        // Если все вопросы отвечены или показаны, возвращаем null
        if ($log_enabled) {
            error_log('TCM: No unanswered questions found');
        }
        return null;
    }
    
    /**
     * Получение ID пользователя WordPress по Telegram ID
     */
    private function get_wp_user_id($telegram_id) {
        $user = $this->users->get_user_by_telegram_id($telegram_id);
        if ($user) {
            return $user->ID;
        }
        return 0;
    }
    
    /**
     * Обработка анкеты
     */
    private function handle_questionnaire($chat_id, $action, $user_id_telegram) {
        $log_enabled = defined('WP_DEBUG') && WP_DEBUG;
        
        $parts = explode(':', $action);
        $action_type = isset($parts[0]) ? $parts[0] : $action;
        // Важно: параметры могут содержать несколько сегментов через ':'
        // Пример: edit:section_key:question_key
        $action_param = count($parts) > 1 ? implode(':', array_slice($parts, 1)) : '';
        
        switch ($action_type) {
            case 'start':
                return $this->start_questionnaire($chat_id, $user_id_telegram);
                
            case 'skip':
                $this->send_reply($chat_id, '✅ Хорошо, вы можете заполнить анкету позже через меню "Настройки".');
                return true;
                
            case 'section':
                $section_key = $action_param;
                return $this->show_questionnaire_section($chat_id, $section_key, $user_id_telegram);
                
            case 'skip_question':
                // Обрабатывается в handle_skip_question
                return false;
                
            case 'ai_assistant':
                // Обрабатывается в handle_ai_assistant
                return false;
                
            case 'back_to_question':
                // Возвращаемся к текущему вопросу
                $wp_user_id = $this->get_wp_user_id($user_id_telegram);
                if ($wp_user_id) {
                    return $this->show_next_questionnaire_question($chat_id, $user_id_telegram, $wp_user_id);
                }
                return false;
                
            case 'edit':
                // Редактирование вопроса анкеты
                $params = explode(':', $action_param);
                if (count($params) >= 2) {
                    $edit_section_key = $params[0];
                    $edit_question_key = $params[1];
                    return $this->edit_questionnaire_question($chat_id, $edit_section_key, $edit_question_key, $user_id_telegram);
                }
                return false;
                
            case 'custom':
                // Обработка "Свой вариант"
                $params = explode(':', $action_param);
                if (count($params) >= 2) {
                    $custom_section_key = $params[0];
                    $custom_question_key = $params[1];
                    return $this->handle_custom_option($chat_id, $custom_section_key, $custom_question_key, $user_id_telegram);
                }
                return false;
                
            default:
                if ($log_enabled) {
                    error_log('TCM: Unknown questionnaire action: ' . $action);
                }
                return false;
        }
    }
    
    /**
     * Начало заполнения анкеты
     */
    private function start_questionnaire($chat_id, $user_id_telegram) {
        $structure = $this->get_questionnaire_structure();
        
        $text = "📋 <b>Анкета для персонализации рекомендаций</b>\n\n" .
                "Анкета состоит из 7 разделов. Вы можете заполнять её постепенно.\n\n" .
                "Выберите раздел для начала:";
        
        $keyboard = array();
        foreach ($structure as $section_key => $section) {
            $keyboard[] = array(
                array(
                    'text' => '📂 ' . $section['title'],
                    'callback_data' => 'questionnaire:section:' . $section_key
                )
            );
        }
        
        $keyboard[] = array(
            array('text' => '🏠 Главное меню', 'callback_data' => 'menu')
        );
        
        return $this->send_reply_with_keyboard($chat_id, $text, $keyboard);
    }
    
    /**
     * Показ раздела анкеты
     */
    private function show_questionnaire_section($chat_id, $section_key, $user_id_telegram) {
        $structure = $this->get_questionnaire_structure();
        
        if (!isset($structure[$section_key])) {
            $this->send_reply($chat_id, '❌ Раздел не найден.');
            return false;
        }
        
        $section = $structure[$section_key];
        $wp_user_id = $this->get_wp_user_id($user_id_telegram);
        if (!$wp_user_id) {
            $this->send_reply($chat_id, '❌ Пользователь не найден. Пожалуйста, сначала зарегистрируйтесь через /register');
            return false;
        }
        
        $answers = get_user_meta($wp_user_id, 'tcm_questionnaire_answers', true);
        if (!is_array($answers)) {
            $answers = array();
        }
        
        $text = "📋 <b>" . $section['title'] . "</b>\n\n";
        
        // Показываем вопросы раздела
        $question_num = 1;
        foreach ($section['questions'] as $question_key => $question) {
            $answer = isset($answers[$section_key][$question_key]) ? $answers[$section_key][$question_key] : '';
            $status = !empty($answer) ? '✅' : '⬜';
            
            $text .= $status . " <b>Вопрос " . $question_num . ":</b> " . $question['text'] . "\n";
            
            if (!empty($answer)) {
                if (is_array($answer)) {
                    $text .= "   Ответ: " . implode(', ', $answer) . "\n";
                } else {
                    $text .= "   Ответ: " . $answer . "\n";
                }
            } elseif (isset($question['hint'])) {
                $text .= "   💡 " . $question['hint'] . "\n";
            }
            
            // Показываем варианты ответа для вопросов с выбором
            if (isset($question['options']) && is_array($question['options'])) {
                $text .= "   Варианты:\n";
                $option_num = 1;
                foreach ($question['options'] as $option) {
                    $text .= "   " . $option_num . ". " . $option . "\n";
                    $option_num++;
                }
            }
            
            $text .= "\n";
            $question_num++;
        }
        
        $text .= "\n💡 <b>Как отвечать:</b>\n";
        $text .= "• Для вопросов с вариантами ответа - отправьте номер варианта или текст\n";
        $text .= "• Для вопросов с несколькими вариантами - отправьте номера через запятую (например: 1, 3, 5)\n";
        $text .= "• Для текстовых вопросов - отправьте ваш ответ\n\n";
        $text .= "Отправьте сообщение в формате: <code>номер_вопроса: ваш_ответ</code>\n";
        $text .= "Например: <code>1: 25</code> или <code>2: 1,3</code>\n\n";
        $text .= "Или используйте кнопки ниже для редактирования ответов.";
        
        $keyboard = array();
        
        // Добавляем кнопки редактирования для каждого вопроса
        $question_num = 1;
        $edit_buttons = array();
        foreach ($section['questions'] as $question_key => $question) {
            $answer = isset($answers[$section_key][$question_key]) ? $answers[$section_key][$question_key] : '';
            if (!empty($answer)) {
                $answer_preview = is_array($answer) ? implode(', ', array_slice($answer, 0, 1)) : mb_substr($answer, 0, 15);
                if (is_array($answer) && count($answer) > 1) {
                    $answer_preview .= '...';
                } elseif (!is_array($answer) && mb_strlen($answer) > 15) {
                    $answer_preview .= '...';
                }
                $button_text = '✏️ ' . $question_num . ': ' . $answer_preview;
                // Ограничиваем длину кнопки (максимум 64 символа для Telegram)
                if (mb_strlen($button_text) > 50) {
                    $button_text = '✏️ Вопрос ' . $question_num;
                }
                $edit_buttons[] = array(
                    'text' => $button_text,
                    'callback_data' => 'questionnaire:edit:' . $section_key . ':' . $question_key
                );
            }
            $question_num++;
        }
        
        // Добавляем кнопки редактирования (по 2 в ряд, если их много)
        if (!empty($edit_buttons)) {
            $chunked_buttons = array_chunk($edit_buttons, 2);
            foreach ($chunked_buttons as $chunk) {
                $keyboard[] = $chunk;
            }
        }
        
        // Кнопки навигации
        $keyboard[] = array(
            array('text' => '📋 К другим разделам', 'callback_data' => 'questionnaire:start')
        );
        $keyboard[] = array(
            array('text' => '🏠 Главное меню', 'callback_data' => 'menu')
        );
        
        // Сохраняем текущий раздел для обработки ответов
        update_user_meta($wp_user_id, 'tcm_questionnaire_current_section', $section_key);
        
        return $this->send_reply_with_keyboard($chat_id, $text, $keyboard);
    }
    
    /**
     * Редактирование вопроса анкеты
     */
    private function edit_questionnaire_question($chat_id, $section_key, $question_key, $user_id_telegram) {
        $structure = $this->get_questionnaire_structure();
        
        if (!isset($structure[$section_key]['questions'][$question_key])) {
            $this->send_reply($chat_id, '❌ Вопрос не найден.');
            return false;
        }
        
        $wp_user_id = $this->get_wp_user_id($user_id_telegram);
        if (!$wp_user_id) {
            $this->send_reply($chat_id, '❌ Пользователь не найден.');
            return false;
        }
        
        $question = $structure[$section_key]['questions'][$question_key];
        $answers = get_user_meta($wp_user_id, 'tcm_questionnaire_answers', true);
        if (!is_array($answers)) {
            $answers = array();
        }
        
        $current_answer = isset($answers[$section_key][$question_key]) ? $answers[$section_key][$question_key] : '';
        
        // Формируем текст вопроса
        $text = "✏️ <b>Редактирование ответа</b>\n\n";
        $text .= "<b>" . $question['text'] . "</b>\n\n";
        
        // Показываем текущий ответ, если есть
        if (!empty($current_answer)) {
            $answer_display = is_array($current_answer) ? implode(', ', $current_answer) : $current_answer;
            $text .= "📝 <b>Текущий ответ:</b> " . $answer_display . "\n\n";
        }
        
        // Показываем варианты ответа в скобках
        if (isset($question['options']) && is_array($question['options'])) {
            $options_list = array();
            $option_num = 1;
            foreach ($question['options'] as $option) {
                $options_list[] = $option_num . ". " . $option;
                $option_num++;
            }
            $text .= "Варианты ответа: (" . implode(", ", $options_list) . ")\n\n";
            
            if ($question['type'] === 'multiple') {
                $text .= "💡 Вы можете выбрать несколько вариантов, указав номера через запятую (например: 1, 3, 5)\n\n";
            } else {
                $text .= "💡 Укажите номер варианта или введите текст ответа\n\n";
            }
        } else {
            if (isset($question['hint'])) {
                $text .= "💡 " . $question['hint'] . "\n\n";
            } else {
                $text .= "💡 Введите ваш ответ текстом\n\n";
            }
        }
        
        $text .= "Отправьте новый ответ на этот вопрос.";
        
        // Создаем клавиатуру
        $keyboard = array(
            array(
                array('text' => '❌ Отмена', 'callback_data' => 'questionnaire:section:' . $section_key)
            )
        );
        
        // Сохраняем текущий вопрос для обработки ответа
        update_user_meta($wp_user_id, 'tcm_questionnaire_current_question', array(
            'section_key' => $section_key,
            'question_key' => $question_key,
            'is_editing' => true
        ));
        
        return $this->send_reply_with_keyboard($chat_id, $text, $keyboard);
    }
    
    /**
     * Обработка ответа на вопрос анкеты
     */
    private function process_questionnaire_answer($chat_id, $user_id_telegram, $section_key, $question_num, $answer_text) {
        $log_enabled = defined('WP_DEBUG') && WP_DEBUG;
        
        $structure = $this->get_questionnaire_structure();
        if (!isset($structure[$section_key])) {
            $this->send_reply($chat_id, '❌ Раздел не найден.');
            return false;
        }
        
        $section = $structure[$section_key];
        $questions = array_values($section['questions']);
        
        if ($question_num < 1 || $question_num > count($questions)) {
            $this->send_reply($chat_id, '❌ Неверный номер вопроса. Пожалуйста, используйте формат: <code>номер: ответ</code>');
            return false;
        }
        
        $question = $questions[$question_num - 1];
        $question_key = array_keys($section['questions'])[$question_num - 1];
        
        $wp_user_id = $this->get_wp_user_id($user_id_telegram);
        if (!$wp_user_id) {
            $this->send_reply($chat_id, '❌ Пользователь не найден.');
            return false;
        }
        
        $answers = get_user_meta($wp_user_id, 'tcm_questionnaire_answers', true);
        if (!is_array($answers)) {
            $answers = array();
        }
        if (!isset($answers[$section_key])) {
            $answers[$section_key] = array();
        }
        
        // Обрабатываем ответ в зависимости от типа вопроса
        $processed_answer = '';
        
        if ($question['type'] === 'choice' && isset($question['options'])) {
            // Выбор одного варианта
            $option_num = intval($answer_text);
            if ($option_num >= 1 && $option_num <= count($question['options'])) {
                $processed_answer = $question['options'][$option_num - 1];
            } else {
                // Пытаемся найти по тексту
                $found = false;
                foreach ($question['options'] as $option) {
                    if (mb_strtolower($option) === mb_strtolower($answer_text)) {
                        $processed_answer = $option;
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $this->send_reply($chat_id, '❌ Неверный вариант ответа. Пожалуйста, выберите номер из списка или введите точный текст варианта.');
                    return false;
                }
            }
        } elseif ($question['type'] === 'multiple' && isset($question['options'])) {
            // Выбор нескольких вариантов
            $selected_nums = array_map('trim', explode(',', $answer_text));
            $selected_options = array();
            
            foreach ($selected_nums as $num_str) {
                $num = intval($num_str);
                if ($num >= 1 && $num <= count($question['options'])) {
                    $selected_options[] = $question['options'][$num - 1];
                }
            }
            
            if (empty($selected_options)) {
                $this->send_reply($chat_id, '❌ Неверные номера вариантов. Пожалуйста, укажите номера через запятую (например: 1, 3, 5)');
                return false;
            }
            
            $processed_answer = $selected_options;
        } else {
            // Текстовый ответ
            $processed_answer = sanitize_text_field($answer_text);
        }
        
        // Сохраняем ответ
        $answers[$section_key][$question_key] = $processed_answer;
        update_user_meta($wp_user_id, 'tcm_questionnaire_answers', $answers);
        
        if ($log_enabled) {
            error_log('TCM: Questionnaire answer saved. Section: ' . $section_key . ', Question: ' . $question_key . ', Answer: ' . print_r($processed_answer, true));
        }
        
        // Формируем сообщение об успешном сохранении
        $answer_display = is_array($processed_answer) ? implode(', ', $processed_answer) : $processed_answer;
        $message = "✅ <b>Ответ сохранен!</b>\n\n" .
                   "Вопрос: " . $question['text'] . "\n" .
                   "Ваш ответ: " . $answer_display . "\n\n" .
                   "Вы можете продолжить заполнение анкеты или вернуться к другим разделам.";
        
        $keyboard = array(
            array(
                array('text' => '📋 Продолжить раздел', 'callback_data' => 'questionnaire:section:' . $section_key),
                array('text' => '📂 К другим разделам', 'callback_data' => 'questionnaire:start')
            ),
            array(
                array('text' => '🏠 Главное меню', 'callback_data' => 'menu')
            )
        );
        
        return $this->send_reply_with_keyboard($chat_id, $message, $keyboard);
    }
    
    /**
     * Обработка согласия на сбор данных
     */
    private function handle_consent($chat_id, $action, $user_id_telegram) {
        $wp_user_id = $this->get_wp_user_id($user_id_telegram);
        if (!$wp_user_id) {
            $this->send_reply($chat_id, '❌ Пользователь не найден. Пожалуйста, сначала зарегистрируйтесь через /register');
            return false;
        }
        
        $log_enabled = defined('WP_DEBUG') && WP_DEBUG;
        
        if ($action === 'yes') {
            update_user_meta($wp_user_id, 'tcm_data_collection_consent', true);
            update_user_meta($wp_user_id, 'tcm_data_collection_consent_date', current_time('mysql'));
            
            if ($log_enabled) {
                error_log('TCM: User gave consent. WP User ID: ' . $wp_user_id);
            }
            
            $this->send_reply($chat_id, '✅ Спасибо за согласие! Мы начнем задавать вопросы постепенно после ваших сообщений.');
            
            // Небольшая задержка перед показом вопроса
            usleep(500000); // 0.5 секунды
            
            // Показываем первый вопрос
            $this->show_next_questionnaire_question($chat_id, $user_id_telegram, $wp_user_id);
        } else {
            update_user_meta($wp_user_id, 'tcm_data_collection_consent', false);
            $this->send_reply($chat_id, '✅ Понятно. Вы можете дать согласие позже через меню "Настройки".');
        }
        
        return true;
    }
    
    /**
     * Упрощенная обработка ответа на вопрос анкеты (после каждого сообщения)
     */
    private function process_questionnaire_answer_simple($chat_id, $user_id_telegram, $wp_user_id, $answer_text, $current_question) {
        $log_enabled = defined('WP_DEBUG') && WP_DEBUG;
        
        $section_key = $current_question['section_key'];
        $question_key = $current_question['question_key'];
        
        $structure = $this->get_questionnaire_structure();
        if (!isset($structure[$section_key]['questions'][$question_key])) {
            // Очищаем текущий вопрос и продолжаем как обычное сообщение
            delete_user_meta($wp_user_id, 'tcm_questionnaire_current_question');
            return false; // Продолжаем обработку как обычное сообщение
        }
        
        $question = $structure[$section_key]['questions'][$question_key];
        
        $answers = get_user_meta($wp_user_id, 'tcm_questionnaire_answers', true);
        if (!is_array($answers)) {
            $answers = array();
        }
        if (!isset($answers[$section_key])) {
            $answers[$section_key] = array();
        }
        
        // Обрабатываем ответ в зависимости от типа вопроса
        $processed_answer = '';
        
        if ($question['type'] === 'choice' && isset($question['options'])) {
            // Выбор одного варианта
            $option_num = intval($answer_text);
            if ($option_num >= 1 && $option_num <= count($question['options'])) {
                $processed_answer = $question['options'][$option_num - 1];
            } else {
                // Пытаемся найти по тексту
                $found = false;
                foreach ($question['options'] as $option) {
                    if (mb_strtolower($option) === mb_strtolower($answer_text)) {
                        $processed_answer = $option;
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    // Неверный ответ
                    $is_editing = isset($current_question['is_editing']) && $current_question['is_editing'];
                    if ($is_editing) {
                        // В режиме редактирования показываем ошибку
                        $this->send_reply($chat_id, '❌ Неверный вариант ответа. Пожалуйста, выберите номер из списка или введите точный текст варианта.');
                        return true; // Не создаем запись
                    } else {
                        // Не в режиме редактирования - пропускаем
                        delete_user_meta($wp_user_id, 'tcm_questionnaire_current_question');
                        return false; // Продолжаем как обычное сообщение
                    }
                }
            }
        } elseif ($question['type'] === 'multiple' && isset($question['options'])) {
            // Выбор нескольких вариантов
            $selected_nums = array_map('trim', explode(',', $answer_text));
            $selected_options = array();
            
            foreach ($selected_nums as $num_str) {
                $num = intval($num_str);
                if ($num >= 1 && $num <= count($question['options'])) {
                    $selected_options[] = $question['options'][$num - 1];
                }
            }
            
            if (empty($selected_options)) {
                // Неверный ответ
                $is_editing = isset($current_question['is_editing']) && $current_question['is_editing'];
                if ($is_editing) {
                    // В режиме редактирования показываем ошибку
                    $this->send_reply($chat_id, '❌ Неверные номера вариантов. Пожалуйста, укажите номера через запятую (например: 1, 3, 5)');
                    return true; // Не создаем запись
                } else {
                    // Не в режиме редактирования - пропускаем
                    delete_user_meta($wp_user_id, 'tcm_questionnaire_current_question');
                    return false;
                }
            }
            
            $processed_answer = $selected_options;
        } elseif ($question['type'] === 'date') {
            // Дата рождения - проверяем формат
            $date_pattern = '/^(\d{1,2})[.\/](\d{1,2})[.\/](\d{4})$|^(\d{4})[.\/-](\d{1,2})[.\/-](\d{1,2})$/';
            if (preg_match($date_pattern, $answer_text, $matches)) {
                // Пытаемся распарсить дату
                if (isset($matches[4])) {
                    // Формат ГГГГ-ММ-ДД
                    $processed_answer = $matches[4] . '-' . str_pad($matches[5], 2, '0', STR_PAD_LEFT) . '-' . str_pad($matches[6], 2, '0', STR_PAD_LEFT);
                } else {
                    // Формат ДД.ММ.ГГГГ
                    $processed_answer = $matches[3] . '-' . str_pad($matches[2], 2, '0', STR_PAD_LEFT) . '-' . str_pad($matches[1], 2, '0', STR_PAD_LEFT);
                }
                
                // Проверяем валидность даты
                $date_obj = DateTime::createFromFormat('Y-m-d', $processed_answer);
                if (!$date_obj || $date_obj->format('Y-m-d') !== $processed_answer) {
                    // Неверный формат даты, но сохраняем как есть
                    $processed_answer = sanitize_text_field($answer_text);
                }
            } else {
                // Неверный формат, но сохраняем как есть
                $processed_answer = sanitize_text_field($answer_text);
            }
        } else {
            // Текстовый ответ
            $processed_answer = sanitize_text_field($answer_text);
        }
        
        // Проверяем, является ли это редактированием
        $is_editing = isset($current_question['is_editing']) && $current_question['is_editing'];
        
        // Сохраняем ответ
        $answers[$section_key][$question_key] = $processed_answer;
        update_user_meta($wp_user_id, 'tcm_questionnaire_answers', $answers);
        
        // Очищаем текущий вопрос
        delete_user_meta($wp_user_id, 'tcm_questionnaire_current_question');
        
        if ($log_enabled) {
            error_log('TCM: Questionnaire answer saved. Section: ' . $section_key . ', Question: ' . $question_key . ', Answer: ' . print_r($processed_answer, true) . ', Is editing: ' . ($is_editing ? 'yes' : 'no'));
        }
        
        // Показываем подтверждение
        $answer_display = is_array($processed_answer) ? implode(', ', $processed_answer) : $processed_answer;
        $status = $this->get_questionnaire_status($user_id_telegram);
        
        if ($is_editing) {
            // Режим редактирования - возвращаем в раздел анкеты
            $message = "✅ <b>Ответ обновлен!</b>\n\n" .
                       "Вопрос: " . $question['text'] . "\n" .
                       "Новый ответ: " . $answer_display . "\n\n" .
                       "Прогресс заполнения: " . $status['progress'] . "% (" . $status['answered'] . " из " . $status['total'] . " вопросов)";
            
            $this->send_reply($chat_id, $message);
            
            // Небольшая задержка перед возвратом в раздел
            usleep(500000); // 0.5 секунды
            
            // Возвращаемся в раздел анкеты
            return $this->show_questionnaire_section($chat_id, $section_key, $user_id_telegram);
        } else {
            // Обычный режим - проверяем, есть ли ожидающий запрос помощи ИИ
            $pending_ai_help = get_user_meta($wp_user_id, 'tcm_pending_ai_help', true);
            
            if (!empty($pending_ai_help) && is_array($pending_ai_help)) {
                // Есть ожидающий запрос помощи ИИ - продолжаем его
                delete_user_meta($wp_user_id, 'tcm_pending_ai_help');
                
                // Показываем подтверждение
                $message = "✅ <b>Ответ сохранен!</b>\n\n" .
                           "Ваш ответ: " . $answer_display . "\n\n" .
                           "Продолжаю формирование помощи от ИИ ассистента...";
                $this->send_reply($chat_id, $message);
                
                // Получаем помощь ИИ
                $this->get_ai_help_after_questionnaire(
                    $chat_id, 
                    $pending_ai_help['category_id'], 
                    $pending_ai_help['category_name'], 
                    $pending_ai_help['level_name'], 
                    $wp_user_id
                );
                
                return true;
            }
            
            // Обычный режим - показываем подтверждение и "Что дальше?"
            $message = "✅ <b>Ответ сохранен!</b>\n\n" .
                       "Ваш ответ: " . $answer_display . "\n\n" .
                       "Прогресс заполнения: " . $status['progress'] . "% (" . $status['answered'] . " из " . $status['total'] . " вопросов)";
            
            $this->send_reply($chat_id, $message);
            
            // Получаем текущую выбранную категорию пользователя
            $current_category_id = $this->get_category_for_chat($chat_id, $user_id_telegram);
            
            if ($current_category_id) {
                $current_category = get_category($current_category_id);
                if ($current_category) {
                    // Определяем уровень категории
                    $level = 0;
                    $current = $current_category;
                    while ($current && $current->parent > 0) {
                        $level++;
                        $current = get_category($current->parent);
                        if (!$current) {
                            break;
                        }
                    }
                    
                    // Если это точка (уровень 2), показываем "Что дальше?"
                    if ($level == 2) {
                        // Небольшая задержка перед показом "Что дальше?"
                        usleep(300000); // 0.3 секунды
                        $this->offer_next_point($chat_id, $user_id_telegram, $current_category_id);
                    }
                }
            }
        }
        
        // Возвращаем true, чтобы НЕ создавать запись из ответа на вопрос анкеты
        return true;
    }
    
    /**
     * Обработка пропуска вопроса анкеты
     */
    private function handle_skip_question($chat_id, $action, $user_id_telegram) {
        $wp_user_id = $this->get_wp_user_id($user_id_telegram);
        if (!$wp_user_id) {
            $this->send_reply($chat_id, '❌ Пользователь не найден.');
            return false;
        }
        
        // Парсим параметры: section_key:question_key
        $parts = explode(':', $action);
        if (count($parts) >= 2) {
            $section_key = $parts[0];
            $question_key = $parts[1];
            $question_id = $section_key . ':' . $question_key;
            
            // Получаем историю показанных вопросов
            $shown_questions = get_user_meta($wp_user_id, 'tcm_questionnaire_shown_questions', true);
            if (!is_array($shown_questions)) {
                $shown_questions = array();
            }
            
            // Добавляем пропущенный вопрос в список пропущенных
            // ИСКЛЮЧЕНИЕ: вопрос про программу не добавляем в пропущенные, чтобы он показывался снова
            $program_question_id = 'section1:program_type';
            if ($question_id !== $program_question_id) {
                if (!isset($shown_questions['skipped'])) {
                    $shown_questions['skipped'] = array();
                }
                if (!in_array($question_id, $shown_questions['skipped'])) {
                    $shown_questions['skipped'][] = $question_id;
                }
            }
            
            // Обновляем историю показанных вопросов
            $shown_questions['last_shown'] = $question_id;
            update_user_meta($wp_user_id, 'tcm_questionnaire_shown_questions', $shown_questions);
        }
        
        // Очищаем текущий вопрос
        delete_user_meta($wp_user_id, 'tcm_questionnaire_current_question');
        
        // Проверяем, есть ли ожидающий запрос помощи ИИ
        $pending_ai_help = get_user_meta($wp_user_id, 'tcm_pending_ai_help', true);
        
        if (!empty($pending_ai_help) && is_array($pending_ai_help)) {
            // Есть ожидающий запрос помощи ИИ - проверяем, есть ли еще вопросы
            $next_question = $this->get_next_unanswered_question($wp_user_id);
            
            if (!$next_question) {
                // Нет больше вопросов - продолжаем получение помощи ИИ
                delete_user_meta($wp_user_id, 'tcm_pending_ai_help');
                $this->send_reply($chat_id, '✅ Вопрос пропущен. Продолжаю формирование помощи от ИИ ассистента...');
                $this->get_ai_help_after_questionnaire(
                    $chat_id, 
                    $pending_ai_help['category_id'], 
                    $pending_ai_help['category_name'], 
                    $pending_ai_help['level_name'], 
                    $wp_user_id
                );
                return true;
            } else {
                // Есть еще вопросы - показываем следующий
                $this->send_reply($chat_id, '✅ Вопрос пропущен. Перехожу к следующему.');
                return $this->show_questionnaire_question_for_ai_help($chat_id, $user_id_telegram, $wp_user_id);
            }
        } else {
            // Нет ожидающего запроса помощи ИИ - обычный режим
            $this->send_reply($chat_id, '✅ Вопрос пропущен. Перехожу к следующему.');
            return $this->show_next_questionnaire_question($chat_id, $user_id_telegram, $wp_user_id);
        }
    }
    
    /**
     * Обработка продолжения получения помощи ИИ без ответа на вопрос
     */
    private function handle_continue_ai_help_without_answer($chat_id, $user_id_telegram) {
        $wp_user_id = $this->get_wp_user_id($user_id_telegram);
        if (!$wp_user_id) {
            $this->send_reply($chat_id, '❌ Пользователь не найден.');
            return false;
        }
        
        // Получаем ожидающий запрос помощи ИИ
        $pending_ai_help = get_user_meta($wp_user_id, 'tcm_pending_ai_help', true);
        
        if (empty($pending_ai_help) || !is_array($pending_ai_help)) {
            $this->send_reply($chat_id, '❌ Не найден запрос помощи ИИ.');
            return false;
        }
        
        // Очищаем текущий вопрос и ожидающий запрос
        delete_user_meta($wp_user_id, 'tcm_questionnaire_current_question');
        delete_user_meta($wp_user_id, 'tcm_pending_ai_help');
        
        // Сразу получаем помощь ИИ
        $this->get_ai_help_after_questionnaire(
            $chat_id, 
            $pending_ai_help['category_id'], 
            $pending_ai_help['category_name'], 
            $pending_ai_help['level_name'], 
            $wp_user_id
        );
        
        return true;
    }
    
    /**
     * Обработка запроса ИИ ассистента
     */
    private function handle_ai_assistant($chat_id, $action, $user_id_telegram) {
        $wp_user_id = $this->get_wp_user_id($user_id_telegram);
        if (!$wp_user_id) {
            $this->send_reply($chat_id, '❌ Пользователь не найден.');
            return false;
        }
        
        // Парсим параметры: section_key:question_key
        $parts = explode(':', $action);
        $section_key = isset($parts[0]) ? $parts[0] : '';
        $question_key = isset($parts[1]) ? $parts[1] : '';
        
        // Получаем информацию о текущем вопросе
        $structure = $this->get_questionnaire_structure();
        $question_text = '';
        if (!empty($section_key) && !empty($question_key) && isset($structure[$section_key]['questions'][$question_key])) {
            $question_text = $structure[$section_key]['questions'][$question_key]['text'];
        }
        
        // Проверяем, есть ли у пользователя PRO тариф
        $is_pro = get_user_meta($wp_user_id, 'tcm_pro_subscription', true);
        
        if ($is_pro) {
            // У пользователя есть PRO - показываем помощь ИИ ассистента
            $this->show_ai_assistant_help($chat_id, $section_key, $question_key, $question_text);
        } else {
            // Предлагаем подключить PRO тариф
            $this->show_pro_offer($chat_id, $section_key, $question_key, $question_text);
        }
        
        return true;
    }
    
    /**
     * Показ предложения PRO тарифа
     */
    private function show_pro_offer($chat_id, $section_key, $question_key, $question_text) {
        $wp_user_id = $this->get_wp_user_id($chat_id);
        if (!$wp_user_id) {
            $this->send_reply($chat_id, '❌ Пользователь не найден. Пожалуйста, зарегистрируйтесь через /register или /link');
            return;
        }
        $text = "🤖 <b>ИИ Ассистент - Тариф PRO</b>\n\n";
        $text .= "Для доступа к ИИ ассистенту необходимо подключить тариф <b>PRO</b>.\n\n";
        $text .= "📋 <b>Что входит в тариф PRO:</b>\n\n";
        $text .= "✅ <b>ИИ помощник в работе по Шагам</b>\n";
        $text .= "Для каждой выбранной точки вы получите:\n\n";
        $text .= "📚 <b>Выдержки из одобренной литературы</b>\n";
        $text .= "Релевантные цитаты и выдержки из проверенных источников, специально подобранные для текущей точки вашего пути.\n\n";
        $text .= "👥 <b>Примеры из жизни других зависимых</b>\n";
        $text .= "Реальные истории людей, которые прошли через похожие ситуации в этой конкретной точке, чтобы помочь вам понять, как применить программу в действии.\n\n";
        $text .= "🎯 <b>Практические рекомендации по применению программы</b>\n";
        $text .= "Конкретные советы и пошаговые инструкции, адаптированные именно для текущей точки, которые помогут вам продвинуться дальше.\n\n";
        $text .= "💡 <b>Персонализированные советы по текущему вопросу/Шагу</b>\n";
        $text .= "Индивидуальные рекомендации, учитывающие ваш уникальный путь и обстоятельства, специально для этой точки.\n\n";
        
        if (!empty($question_text)) {
            $text .= "🔍 <b>По текущему вопросу:</b> " . $question_text . "\n";
            $text .= "ИИ ассистент предоставит интерактивную помощь именно по этому вопросу, адаптированную под вашу ситуацию.\n\n";
        }
        
        $text .= "🎁 <b>Дополнительные функции PRO:</b>\n";
        $text .= "• Приоритетная поддержка 24/7\n";
        $text .= "• Расширенная аналитика вашего прогресса\n";
        $text .= "• Персональные рекомендации на основе ваших ответов\n";
        $text .= "• Доступ к эксклюзивным материалам и ресурсам\n\n";
        $text .= "💬 Для подключения тарифа PRO перейдите по ссылке ниже.";
        
        // Получаем Telegram ID пользователя (chat_id может быть Telegram ID)
        $telegram_id = get_user_meta($wp_user_id, 'tcm_telegram_id', true);
        if (empty($telegram_id)) {
            // Используем chat_id как Telegram ID
            $telegram_id = $chat_id;
        }
        
        // Получаем ссылку на оплату с Telegram ID
        $payment_class = new TCM_Payment();
        $payment_url = $payment_class->get_payment_url($wp_user_id, 30, $telegram_id);
        
        $keyboard = array();
        
        if ($payment_url) {
            $keyboard[] = array(
                array('text' => '💳 Оплатить PRO подписку', 'url' => $payment_url)
            );
        }
        
        $keyboard[] = array(
            array('text' => '📞 Связаться с администратором', 'callback_data' => 'support')
        );
        
        $keyboard[] = array(
            array('text' => '⬅️ Назад к вопросу', 'callback_data' => 'questionnaire:back_to_question')
        );
        
        $this->send_reply_with_keyboard($chat_id, $text, $keyboard);
    }
    
    /**
     * Показ помощи ИИ ассистента (для PRO пользователей)
     */
    private function show_ai_assistant_help($chat_id, $section_key, $question_key, $question_text) {
        $text = "🤖 <b>ИИ Ассистент</b>\n\n";
        
        if (!empty($question_text)) {
            $text .= "📋 <b>Вопрос:</b> " . $question_text . "\n\n";
        }
        
        $text .= "💡 <b>Помощь по этому вопросу:</b>\n\n";
        
        // Здесь можно добавить логику получения помощи от ИИ
        // Пока показываем заглушку
        $text .= "📚 <b>Выдержки из литературы:</b>\n";
        $text .= "В работе по программе важно честно отвечать на вопросы анкеты. Это поможет лучше понять вашу ситуацию и предложить более точные рекомендации.\n\n";
        
        $text .= "👥 <b>Примеры из жизни:</b>\n";
        $text .= "Многие участники программы отмечают, что честные ответы помогают им лучше понять себя и свои мотивы.\n\n";
        
        $text .= "🎯 <b>Рекомендации:</b>\n";
        $text .= "• Отвечайте честно, даже если ответы кажутся неудобными\n";
        $text .= "• Не торопитесь, подумайте над каждым вопросом\n";
        $text .= "• Помните, что все ответы конфиденциальны\n\n";
        
        $text .= "💬 Если у вас есть дополнительные вопросы, вы можете задать их здесь или обратиться к спонсору.";
        
        $keyboard = array(
            array(
                array('text' => '⬅️ Назад к вопросу', 'callback_data' => 'questionnaire:back_to_question')
            )
        );
        
        $this->send_reply_with_keyboard($chat_id, $text, $keyboard);
    }
    
    /**
     * Обработка выбора варианта ответа через кнопку
     */
    private function handle_select_option($chat_id, $action, $user_id_telegram) {
        $wp_user_id = $this->get_wp_user_id($user_id_telegram);
        if (!$wp_user_id) {
            $this->answer_callback_query($action, '❌ Пользователь не найден', true);
            return false;
        }
        
        // Парсим параметры: section_key:question_key:option_index
        $parts = explode(':', $action);
        if (count($parts) < 3) {
            $this->answer_callback_query($action, '❌ Ошибка параметров', true);
            return false;
        }
        
        $section_key = $parts[0];
        $question_key = $parts[1];
        $option_index = intval($parts[2]);
        
        $structure = $this->get_questionnaire_structure();
        if (!isset($structure[$section_key]['questions'][$question_key])) {
            $this->answer_callback_query($action, '❌ Вопрос не найден', true);
            return false;
        }
        
        $question = $structure[$section_key]['questions'][$question_key];
        
        if (!isset($question['options']) || !is_array($question['options']) || $option_index < 0 || $option_index >= count($question['options'])) {
            $this->answer_callback_query($action, '❌ Неверный вариант', true);
            return false;
        }
        
        $selected_option = $question['options'][$option_index];
        
        // Получаем текущие ответы
        $answers = get_user_meta($wp_user_id, 'tcm_questionnaire_answers', true);
        if (!is_array($answers)) {
            $answers = array();
        }
        if (!isset($answers[$section_key])) {
            $answers[$section_key] = array();
        }
        
        // Обрабатываем в зависимости от типа вопроса
        if ($question['type'] === 'multiple') {
            // Для множественного выбора - добавляем/удаляем вариант
            if (!isset($answers[$section_key][$question_key]) || !is_array($answers[$section_key][$question_key])) {
                $answers[$section_key][$question_key] = array();
            }
            
            // Проверяем, есть ли уже этот вариант
            $option_index_in_array = array_search($selected_option, $answers[$section_key][$question_key]);
            if ($option_index_in_array !== false) {
                // Удаляем вариант (снимаем выбор)
                unset($answers[$section_key][$question_key][$option_index_in_array]);
                $answers[$section_key][$question_key] = array_values($answers[$section_key][$question_key]); // Переиндексируем
                $message = "❌ Вариант \"" . $selected_option . "\" удален";
            } else {
                // Добавляем вариант
                $answers[$section_key][$question_key][] = $selected_option;
                $message = "✅ Вариант \"" . $selected_option . "\" добавлен";
            }
        } else {
            // Для одиночного выбора - просто заменяем
            $answers[$section_key][$question_key] = $selected_option;
            $message = "✅ Выбран вариант \"" . $selected_option . "\"";
        }
        
        // Сохраняем ответы
        update_user_meta($wp_user_id, 'tcm_questionnaire_answers', $answers);
        
        // Проверяем, есть ли ожидающий запрос помощи ИИ
        $pending_ai_help = get_user_meta($wp_user_id, 'tcm_pending_ai_help', true);
        
        // Для одиночного выбора (choice) - сразу обрабатываем как завершенный ответ
        if ($question['type'] === 'choice' && !empty($pending_ai_help) && is_array($pending_ai_help)) {
            // Очищаем текущий вопрос
            delete_user_meta($wp_user_id, 'tcm_questionnaire_current_question');
            delete_user_meta($wp_user_id, 'tcm_pending_ai_help');
            
            // Показываем подтверждение
            $answer_display = $selected_option;
            $confirm_message = "✅ <b>Ответ сохранен!</b>\n\n" .
                             "Ваш ответ: " . $answer_display . "\n\n" .
                             "Продолжаю формирование помощи от ИИ ассистента...";
            $this->send_reply($chat_id, $confirm_message);
            
            // Получаем помощь ИИ
            $this->get_ai_help_after_questionnaire(
                $chat_id, 
                $pending_ai_help['category_id'], 
                $pending_ai_help['category_name'], 
                $pending_ai_help['level_name'], 
                $wp_user_id
            );
            
            $this->answer_callback_query($action, $message, false);
            return true;
        }
        
        // Обновляем текущий вопрос, чтобы показать обновленную клавиатуру
        $current_question = get_user_meta($wp_user_id, 'tcm_questionnaire_current_question', true);
        if ($current_question && $current_question['section_key'] === $section_key && $current_question['question_key'] === $question_key) {
            // Показываем обновленный вопрос с новыми отметками
            if (isset($current_question['question_num'])) {
                // Если это вопрос для помощи ИИ, показываем его снова
                if (!empty($pending_ai_help) && is_array($pending_ai_help)) {
                    $this->show_questionnaire_question_for_ai_help($chat_id, $user_id_telegram, $wp_user_id);
                } else {
                    $this->show_next_questionnaire_question($chat_id, $user_id_telegram, $wp_user_id);
                }
            }
        }
        
        $this->answer_callback_query($action, $message, false);
        return true;
    }
    
    /**
     * Обработка завершения выбора для множественного вопроса
     */
    private function handle_finish_question($chat_id, $action, $user_id_telegram) {
        $wp_user_id = $this->get_wp_user_id($user_id_telegram);
        if (!$wp_user_id) {
            $this->answer_callback_query($action, '❌ Пользователь не найден', true);
            return false;
        }
        
        // Парсим параметры: section_key:question_key
        $parts = explode(':', $action);
        if (count($parts) < 2) {
            $this->answer_callback_query($action, '❌ Ошибка параметров', true);
            return false;
        }
        
        $section_key = $parts[0];
        $question_key = $parts[1];
        
        // Получаем сохраненные ответы
        $answers = get_user_meta($wp_user_id, 'tcm_questionnaire_answers', true);
        if (!is_array($answers)) {
            $answers = array();
        }
        
        $answer_display = '';
        if (isset($answers[$section_key][$question_key])) {
            if (is_array($answers[$section_key][$question_key])) {
                $answer_display = implode(', ', $answers[$section_key][$question_key]);
            } else {
                $answer_display = $answers[$section_key][$question_key];
            }
        }
        
        // Проверяем, есть ли ожидающий запрос помощи ИИ
        $pending_ai_help = get_user_meta($wp_user_id, 'tcm_pending_ai_help', true);
        
        if (!empty($pending_ai_help) && is_array($pending_ai_help)) {
            // Очищаем текущий вопрос
            delete_user_meta($wp_user_id, 'tcm_questionnaire_current_question');
            delete_user_meta($wp_user_id, 'tcm_pending_ai_help');
            
            // Показываем подтверждение
            $confirm_message = "✅ <b>Ответ сохранен!</b>\n\n" .
                             "Ваш ответ: " . ($answer_display ? $answer_display : 'не выбран') . "\n\n" .
                             "Продолжаю формирование помощи от ИИ ассистента...";
            $this->send_reply($chat_id, $confirm_message);
            
            // Получаем помощь ИИ
            $this->get_ai_help_after_questionnaire(
                $chat_id, 
                $pending_ai_help['category_id'], 
                $pending_ai_help['category_name'], 
                $pending_ai_help['level_name'], 
                $wp_user_id
            );
            
            $this->answer_callback_query($action, '✅ Выбор завершен', false);
            return true;
        }
        
        // Если нет ожидающего запроса помощи ИИ, просто подтверждаем
        $this->answer_callback_query($action, '✅ Выбор завершен', false);
        return true;
    }
    
    /**
     * Обработка запроса на ввод своего варианта ответа
     */
    private function handle_custom_option($chat_id, $section_key, $question_key, $user_id_telegram) {
        $wp_user_id = $this->get_wp_user_id($user_id_telegram);
        if (!$wp_user_id) {
            $this->send_reply($chat_id, '❌ Пользователь не найден.');
            return false;
        }
        
        $structure = $this->get_questionnaire_structure();
        if (!isset($structure[$section_key]['questions'][$question_key])) {
            $this->send_reply($chat_id, '❌ Вопрос не найден.');
            return false;
        }
        
        $question = $structure[$section_key]['questions'][$question_key];
        
        // Сохраняем текущий вопрос для обработки текстового ответа
        update_user_meta($wp_user_id, 'tcm_questionnaire_current_question', array(
            'section_key' => $section_key,
            'question_key' => $question_key,
            'is_custom' => true
        ));
        
        $text = "✏️ <b>Свой вариант</b>\n\n";
        $text .= "<b>Вопрос:</b> " . $question['text'] . "\n\n";
        $text .= "Введите ваш ответ текстом:";
        
        $keyboard = array(
            array(
                array('text' => '❌ Отмена', 'callback_data' => 'questionnaire:back_to_question')
            )
        );
        
        return $this->send_reply_with_keyboard($chat_id, $text, $keyboard);
    }
    
    /**
     * Получение понятного сообщения об ошибке DeepSeek API
     */
    private function get_deepseek_error_message() {
        if (!$this->last_deepseek_error) {
            return null;
        }
        
        $error = $this->last_deepseek_error;
        $code = isset($error['code']) ? $error['code'] : 0;
        $message = isset($error['message']) ? $error['message'] : '';
        
        // Обрабатываем специфичные ошибки
        if ($code == 402 || stripos($message, 'Insufficient Balance') !== false || stripos($message, 'insufficient') !== false) {
            return '❌ Недостаточно средств на балансе DeepSeek API. Обратитесь к администратору для пополнения баланса.';
        }
        
        if ($code == 401 || stripos($message, 'Invalid API key') !== false || stripos($message, 'Unauthorized') !== false) {
            return '❌ Неверный API ключ DeepSeek. Обратитесь к администратору.';
        }
        
        if ($code == 429 || stripos($message, 'rate limit') !== false || stripos($message, 'too many requests') !== false) {
            return '❌ Превышен лимит запросов к DeepSeek API. Попробуйте позже.';
        }
        
        // Обработка таймаутов
        $error_type = isset($error['type']) ? $error['type'] : '';
        if ($error_type == 'timeout' || stripos($message, 'timeout') !== false || stripos($message, 'timed out') !== false || stripos($message, 'Operation timed out') !== false) {
            return '❌ Превышено время ожидания ответа от DeepSeek API (таймаут). Это может быть связано с большой нагрузкой на сервер или медленным интернет-соединением. Попробуйте позже или обратитесь к администратору.';
        }
        
        // Обработка неожиданной структуры ответа
        if ($error_type == 'unexpected_structure') {
            return '❌ Получен неожиданный ответ от DeepSeek API. Попробуйте обновить запрос или обратитесь к администратору.';
        }
        
        // Обработка пустого ответа
        if ($error_type == 'empty_response') {
            return '❌ Получен пустой ответ от DeepSeek API. Попробуйте обновить запрос или обратитесь к администратору.';
        }
        
        if ($code >= 500) {
            return '❌ Временная ошибка сервера DeepSeek API. Попробуйте позже.';
        }
        
        // Общее сообщение с деталями ошибки
        if (!empty($message)) {
            // Сокращаем технические детали для пользователя
            $user_message = $message;
            if (stripos($message, 'cURL error') !== false) {
                // Упрощаем сообщение об ошибке cURL
                if (stripos($message, 'timeout') !== false) {
                    $user_message = 'Таймаут соединения';
                } else {
                    $user_message = 'Ошибка соединения с API';
                }
            }
            return '❌ Ошибка DeepSeek API: ' . $user_message . '. Обратитесь к администратору.';
        }
        
        return null;
    }
    
    /**
     * Форматирование информации о пользователе из анкеты для ИИ
     */
    private function format_user_info_for_ai($questionnaire_answers) {
        $info_parts = array();
        
        // Демография
        if (isset($questionnaire_answers['section1'])) {
            $section1 = $questionnaire_answers['section1'];
            // Программа - самый важный параметр, выводим первым
            if (isset($section1['program_type']) && !empty($section1['program_type'])) {
                $info_parts[] = 'Программа: ' . $section1['program_type'];
            }
            if (isset($section1['birth_date']) && !empty($section1['birth_date'])) {
                $info_parts[] = 'Дата рождения: ' . $section1['birth_date'];
            }
            if (isset($section1['gender']) && !empty($section1['gender'])) {
                $info_parts[] = 'Пол: ' . $section1['gender'];
            }
            if (isset($section1['city']) && !empty($section1['city'])) {
                $info_parts[] = 'Город: ' . $section1['city'];
            }
        }
        
        // Зависимость
        if (isset($questionnaire_answers['section2'])) {
            $section2 = $questionnaire_answers['section2'];
            if (isset($section2['addiction_type']) && !empty($section2['addiction_type'])) {
                $info_parts[] = 'Вид зависимости: ' . (is_array($section2['addiction_type']) ? implode(', ', $section2['addiction_type']) : $section2['addiction_type']);
            }
            if (isset($section2['addiction_duration']) && !empty($section2['addiction_duration'])) {
                $info_parts[] = 'Стаж зависимости: ' . $section2['addiction_duration'];
            }
            if (isset($section2['last_use_date']) && !empty($section2['last_use_date'])) {
                $info_parts[] = 'Дата последнего употребления: ' . $section2['last_use_date'];
            }
        }
        
        // Психологический портрет
        if (isset($questionnaire_answers['section4'])) {
            $section4 = $questionnaire_answers['section4'];
            if (isset($section4['motivation_level']) && !empty($section4['motivation_level'])) {
                $info_parts[] = 'Уровень мотивации: ' . $section4['motivation_level'] . '/10';
            }
            if (isset($section4['strengths']) && !empty($section4['strengths'])) {
                $info_parts[] = 'Сильные стороны: ' . (is_array($section4['strengths']) ? implode(', ', $section4['strengths']) : $section4['strengths']);
            }
        }
        
        return !empty($info_parts) ? implode("\n", $info_parts) : '';
    }
    
    /**
     * Сохранение сообщения в историю диалога пользователя
     */
    private function save_to_conversation_history($wp_user_id, $user_message, $assistant_response, $category_id = null) {
        $history = get_user_meta($wp_user_id, 'tcm_ai_conversation_history', true);
        if (!is_array($history)) {
            $history = array();
        }
        
        // Добавляем запрос пользователя
        $history[] = array(
            'role' => 'user',
            'content' => $user_message,
            'timestamp' => current_time('mysql'),
            'category_id' => $category_id
        );
        
        // Добавляем ответ ассистента
        $history[] = array(
            'role' => 'assistant',
            'content' => $assistant_response,
            'timestamp' => current_time('mysql'),
            'category_id' => $category_id
        );
        
        // Ограничиваем историю последними 10 сообщениями (5 пар вопрос-ответ)
        if (count($history) > 10) {
            $history = array_slice($history, -10);
        }
        
        update_user_meta($wp_user_id, 'tcm_ai_conversation_history', $history);
    }
    
    /**
     * Очистка истории диалога пользователя
     */
    private function clear_conversation_history($wp_user_id) {
        delete_user_meta($wp_user_id, 'tcm_ai_conversation_history');
    }
    
    /**
     * Формирование пользовательского промта для выбранной точки с учетом анкеты
     */
    private function build_point_prompt($category_id, $user_id_telegram) {
        $category = get_category($category_id);
        if (!$category) {
            return '';
        }
        
        // Строим путь категории
        $category_path = array();
        $current_category = $category;
        while ($current_category) {
            $category_path[] = $current_category->name;
            if ($current_category->parent > 0) {
                $current_category = get_category($current_category->parent);
            } else {
                break;
            }
        }
        $category_path = array_reverse($category_path);
        $full_path = implode(' → ', $category_path);
        
        $level_name_prepositional = $this->get_category_level_name($category_id, 'prepositional');
        
        $prompt = "Ты - помощник в программе 12 шагов для людей, страдающих зависимостью. Пользователь выбрал " . $level_name_prepositional . " \"" . $category->name . "\" (полный путь: " . $full_path . ").\n\n";
        $prompt .= "Предоставь помощь по этой точке, включая:\n";
        $prompt .= "1. Выдержки из одобренной литературы, релевантные для этой точки\n";
        $prompt .= "2. Примеры из жизни других зависимых, которые прошли через эту точку\n";
        $prompt .= "3. Практические рекомендации по применению программы в действии для этой конкретной точки\n";
        $prompt .= "4. Персонализированные советы, которые помогут пользователю продвинуться дальше\n\n";
        $prompt .= "Ответ должен быть структурированным, полезным и мотивирующим. Используй форматирование для лучшей читаемости.";
        
        // Добавляем данные анкеты
        $wp_user_id = $this->get_wp_user_id($user_id_telegram);
        if ($wp_user_id) {
            $questionnaire_answers = get_user_meta($wp_user_id, 'tcm_questionnaire_answers', true);
            if (is_array($questionnaire_answers) && !empty($questionnaire_answers)) {
                $user_info = $this->format_user_info_for_ai($questionnaire_answers);
                if (!empty($user_info)) {
                    $prompt .= "\n\nДанные анкеты для персонализации:\n" . $user_info;
                }
            }
        }
        
        return $prompt;
    }
    
    /**
     * Получение помощи от DeepSeek API для выбранной категории
     */
    private function get_deepseek_assistance($category_id, $category_name, $level_name, $wp_user_id = null, $use_cache = true) {
        $log_enabled = defined('WP_DEBUG') && WP_DEBUG;
        
        $api_key = get_option('tcm_deepseek_api_key', '');
        $model = get_option('tcm_deepseek_model', 'deepseek-chat');
        
        if ($log_enabled) {
            error_log('TCM: get_deepseek_assistance called. Category ID: ' . $category_id . ', User ID: ' . $wp_user_id . ', API Key set: ' . (!empty($api_key) ? 'yes' : 'no') . ', Use cache: ' . ($use_cache ? 'yes' : 'no'));
        }
        
        if (empty($api_key)) {
            if ($log_enabled) {
                error_log('TCM: DeepSeek API key is empty');
            }
            return false;
        }
        
        // Проверяем кэш, если включено кэширование
        if ($use_cache && $wp_user_id) {
            $cache_key = 'tcm_ai_help_' . $wp_user_id . '_' . $category_id;
            $cached_response = get_transient($cache_key);
            
            if ($cached_response !== false) {
                if ($log_enabled) {
                    error_log('TCM: Returning cached AI response for user ' . $wp_user_id . ', category ' . $category_id);
                }
                return $cached_response;
            }
        }
        
        // Получаем информацию о категории и её родителях для контекста
        $category_path = array();
        $current_category = get_category($category_id);
        while ($current_category) {
            $category_path[] = $current_category->name;
            if ($current_category->parent > 0) {
                $current_category = get_category($current_category->parent);
            } else {
                break;
            }
        }
        $category_path = array_reverse($category_path);
        $full_path = implode(' → ', $category_path);
        
        // Формируем системный промпт с информацией о пользователе
        $system_prompt = 'Ты опытный консультант по программе 12 шагов, помогающий людям в выздоровлении от зависимости. Твои ответы должны быть поддерживающими, практичными и основанными на принципах программы.';
        
        // Определяем тип программы пользователя для адаптации промпта
        $program_type = '';
        if ($wp_user_id) {
            $questionnaire_answers = get_user_meta($wp_user_id, 'tcm_questionnaire_answers', true);
            if (is_array($questionnaire_answers) && isset($questionnaire_answers['section1']['program_type']) && !empty($questionnaire_answers['section1']['program_type'])) {
                $program_type = $questionnaire_answers['section1']['program_type'];
            }
        }
        
        // Адаптируем промпт в зависимости от типа программы
        if (!empty($program_type)) {
            if (stripos($program_type, 'Анонимные Наркоманы') !== false || stripos($program_type, 'АН') !== false) {
                $system_prompt = 'Ты опытный консультант по программе 12 шагов Анонимных Наркоманов (АН), помогающий людям в выздоровлении от наркотической зависимости. Твои ответы должны быть поддерживающими, практичными и основанными на принципах программы АН. Используй терминологию и подходы, принятые в сообществе АН.';
            } elseif (stripos($program_type, 'Анонимные Алкоголики') !== false || stripos($program_type, 'АА') !== false) {
                $system_prompt = 'Ты опытный консультант по программе 12 шагов Анонимных Алкоголиков (АА), помогающий людям в выздоровлении от алкогольной зависимости. Твои ответы должны быть поддерживающими, практичными и основанными на принципах программы АА. Используй терминологию и подходы, принятые в сообществе АА.';
            } elseif (stripos($program_type, '12 шагов') !== false) {
                $system_prompt = 'Ты опытный консультант по программе 12 шагов, помогающий людям в выздоровлении от зависимости. Твои ответы должны быть поддерживающими, практичными и основанными на принципах программы 12 шагов.';
            } else {
                $system_prompt = 'Ты опытный консультант, помогающий людям в выздоровлении от зависимости. Твои ответы должны быть поддерживающими и практичными.';
            }
        }
        
        // Добавляем информацию из анкеты пользователя, если она есть
        if ($wp_user_id) {
            $questionnaire_answers = get_user_meta($wp_user_id, 'tcm_questionnaire_answers', true);
            if (is_array($questionnaire_answers) && !empty($questionnaire_answers)) {
                $user_info = $this->format_user_info_for_ai($questionnaire_answers);
                if (!empty($user_info)) {
                    $system_prompt .= "\n\nИнформация о пользователе для персонализации ответов:\n" . $user_info;
                }
            }
        }
        
        // Формируем промпт для DeepSeek
        $prompt = "Ты - помощник в программе 12 шагов для людей, страдающих зависимостью. Пользователь выбрал " . $level_name . " \"" . $category_name . "\" (полный путь: " . $full_path . ").\n\n";
        $prompt .= "Предоставь помощь по этой точке, включая:\n";
        $prompt .= "1. Выдержки из одобренной литературы, релевантные для этой точки\n";
        $prompt .= "2. Примеры из жизни других зависимых, которые прошли через эту точку\n";
        $prompt .= "3. Практические рекомендации по применению программы в действии для этой конкретной точки\n";
        $prompt .= "4. Персонализированные советы, которые помогут пользователю продвинуться дальше\n\n";
        $prompt .= "Ответ должен быть структурированным, полезным и мотивирующим. Используй форматирование для лучшей читаемости.";
        
        // Получаем историю диалога пользователя
        $conversation_history = array();
        if ($wp_user_id) {
            $conversation_history = get_user_meta($wp_user_id, 'tcm_ai_conversation_history', true);
            if (!is_array($conversation_history)) {
                $conversation_history = array();
            }
            
            // Ограничиваем историю последними 8 сообщениями (чтобы не превысить лимит токенов)
            if (count($conversation_history) > 8) {
                $conversation_history = array_slice($conversation_history, -8);
            }
        }
        
        // Формируем массив сообщений
        $messages = array(
            array(
                'role' => 'system',
                'content' => $system_prompt
            )
        );
        
        // Добавляем историю диалога
        foreach ($conversation_history as $msg) {
            if (isset($msg['role']) && isset($msg['content'])) {
                $messages[] = array(
                    'role' => $msg['role'],
                    'content' => $msg['content']
                );
            }
        }
        
        // Добавляем текущий запрос
        $messages[] = array(
            'role' => 'user',
            'content' => $prompt
        );
        
        // Отправляем запрос к DeepSeek API
        $api_url = 'https://api.deepseek.com/chat/completions';
        
        $request_body = array(
            'model' => $model,
            'messages' => $messages,
            'temperature' => 0.7,
            'max_tokens' => 1500,
            'stream' => false
        );
        
        if ($log_enabled) {
            error_log('TCM: Sending request to DeepSeek API. URL: ' . $api_url . ', Model: ' . $model);
            error_log('TCM: Request body: ' . json_encode($request_body, JSON_UNESCAPED_UNICODE));
        }
        
        $response = wp_remote_post($api_url, array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $api_key
            ),
            'body' => json_encode($request_body),
            'timeout' => 60
        ));
        
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $error_code = $response->get_error_code();
            error_log('TCM: DeepSeek API error: ' . $error_message);
            if ($log_enabled) {
                error_log('TCM: Full error details: ' . print_r($response, true));
            }
            
            // Определяем тип ошибки для более понятного сообщения
            $error_type = 'wp_error';
            if (stripos($error_message, 'timeout') !== false || stripos($error_message, 'timed out') !== false || $error_code == 'http_request_failed') {
                $error_type = 'timeout';
            }
            
            // Сохраняем детали ошибки для отображения пользователю
            $this->last_deepseek_error = array(
                'code' => 0,
                'message' => $error_message,
                'type' => $error_type,
                'error_code' => $error_code
            );
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        if ($log_enabled) {
            error_log('TCM: DeepSeek API response code: ' . $response_code);
            error_log('TCM: DeepSeek API response body: ' . substr($response_body, 0, 500));
        }
        
        if ($response_code !== 200) {
            error_log('TCM: DeepSeek API returned code ' . $response_code . ': ' . $response_body);
            
            // Парсим ответ для получения деталей ошибки
            $data = json_decode($response_body, true);
            if (isset($data['error'])) {
                $error_message = isset($data['error']['message']) ? $data['error']['message'] : 'Unknown error';
                $error_type = isset($data['error']['type']) ? $data['error']['type'] : 'unknown';
                error_log('TCM: DeepSeek API error: ' . $error_type . ' - ' . $error_message);
                
                // Сохраняем детали ошибки для отображения пользователю
                $this->last_deepseek_error = array(
                    'code' => $response_code,
                    'message' => $error_message,
                    'type' => $error_type
                );
            } else {
                // Ошибка без стандартной структуры DeepSeek
                $this->last_deepseek_error = array(
                    'code' => $response_code,
                    'message' => mb_substr((string)$response_body, 0, 300),
                    'type' => 'http_error'
                );
            }
            return false;
        }
        
        $data = json_decode($response_body, true);
        
        if ($log_enabled) {
            error_log('TCM: Parsed response data: ' . print_r($data, true));
        }
        
        if (isset($data['choices'][0]['message']['content'])) {
            $content = $data['choices'][0]['message']['content'];
            if ($log_enabled) {
                error_log('TCM: Successfully got content from DeepSeek. Length: ' . strlen($content));
                error_log('TCM: Content preview: ' . mb_substr($content, 0, 200));
            }
            
            // Проверяем, что контент не пустой
            if (empty(trim($content))) {
                if ($log_enabled) {
                    error_log('TCM: Content is empty after trimming');
                }
                $this->last_deepseek_error = array(
                    'code' => $response_code,
                    'message' => 'Ответ от DeepSeek API пустой',
                    'type' => 'empty_response'
                );
                return false;
            }
            
            // Очищаем предыдущую ошибку при успехе
            $this->last_deepseek_error = null;
            
            // Сохраняем текущий запрос и ответ в историю диалога
            if ($wp_user_id) {
                $this->save_to_conversation_history($wp_user_id, $prompt, $content, $category_id);
                
                // Сохраняем ответ в кэш (на 24 часа)
                if ($use_cache) {
                    $cache_key = 'tcm_ai_help_' . $wp_user_id . '_' . $category_id;
                    set_transient($cache_key, $content, DAY_IN_SECONDS);
                    if ($log_enabled) {
                        error_log('TCM: Cached AI response for user ' . $wp_user_id . ', category ' . $category_id);
                    }
                }
            }
            
            return $content;
        }
        
        // Проверяем наличие ошибок в ответе
        if (isset($data['error'])) {
            $error_message = isset($data['error']['message']) ? $data['error']['message'] : 'Unknown error';
            $error_type = isset($data['error']['type']) ? $data['error']['type'] : 'unknown';
            error_log('TCM: DeepSeek API error: ' . $error_type . ' - ' . $error_message);
            if ($log_enabled) {
                error_log('TCM: Full error details: ' . print_r($data['error'], true));
            }
            
            // Сохраняем детали ошибки для отображения пользователю
            $this->last_deepseek_error = array(
                'code' => $response_code,
                'message' => $error_message,
                'type' => $error_type
            );
            return false;
        }
        
        if ($log_enabled) {
            error_log('TCM: No content in response. Response structure: ' . print_r($data, true));
        }
        
        // Сохраняем ошибку для отображения пользователю
        $this->last_deepseek_error = array(
            'code' => $response_code,
            'message' => 'Неожиданная структура ответа от DeepSeek API. Ответ получен, но не содержит ожидаемых данных.',
            'type' => 'unexpected_structure',
            'response_preview' => mb_substr($response_body, 0, 200)
        );
        
        return false;
    }
    
    /**
     * Проверка соединения с DeepSeek API (для админки)
     * 
     * @return array Массив с результатом проверки: success (bool), message (string), details (array)
     */
    public function test_ai_connection() {
        $api_key = get_option('tcm_deepseek_api_key', '');
        $model = get_option('tcm_deepseek_model', 'deepseek-chat');
        
        if (empty($api_key)) {
            return array(
                'success' => false,
                'message' => 'API ключ не указан',
                'details' => array(
                    'api_key_set' => false,
                    'model' => $model
                )
            );
        }
        
        // Отправляем простой тестовый запрос
        $api_url = 'https://api.deepseek.com/chat/completions';
        
        $request_body = array(
            'model' => $model,
            'messages' => array(
                array(
                    'role' => 'user',
                    'content' => 'Привет! Это тестовый запрос для проверки соединения. Ответь одним словом: "ОК".'
                )
            ),
            'temperature' => 0.7,
            'max_tokens' => 10,
            'stream' => false
        );
        
        $response = wp_remote_post($api_url, array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $api_key
            ),
            'body' => json_encode($request_body),
            'timeout' => 15
        ));
        
        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'message' => 'Ошибка соединения: ' . $response->get_error_message(),
                'details' => array(
                    'api_key_set' => true,
                    'model' => $model,
                    'error_type' => 'wp_error',
                    'error_code' => $response->get_error_code()
                )
            );
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        if ($response_code !== 200) {
            $data = json_decode($response_body, true);
            $error_message = 'Неизвестная ошибка';
            $error_type = 'http_error';
            
            if (isset($data['error'])) {
                $error_message = isset($data['error']['message']) ? $data['error']['message'] : 'Unknown error';
                $error_type = isset($data['error']['type']) ? $data['error']['type'] : 'unknown';
            } else {
                $error_message = mb_substr((string)$response_body, 0, 300);
            }
            
            return array(
                'success' => false,
                'message' => 'Ошибка API: ' . $error_message,
                'details' => array(
                    'api_key_set' => true,
                    'model' => $model,
                    'response_code' => $response_code,
                    'error_type' => $error_type,
                    'error_message' => $error_message
                )
            );
        }
        
        $data = json_decode($response_body, true);
        
        if (isset($data['choices'][0]['message']['content'])) {
            $content = $data['choices'][0]['message']['content'];
            return array(
                'success' => true,
                'message' => 'Соединение успешно установлено!',
                'details' => array(
                    'api_key_set' => true,
                    'model' => $model,
                    'response_code' => $response_code,
                    'response_preview' => mb_substr($content, 0, 100),
                    'tokens_used' => isset($data['usage']['total_tokens']) ? $data['usage']['total_tokens'] : 'N/A'
                )
            );
        }
        
        return array(
            'success' => false,
            'message' => 'Неожиданный формат ответа от API',
            'details' => array(
                'api_key_set' => true,
                'model' => $model,
                'response_code' => $response_code,
                'response_body' => mb_substr($response_body, 0, 500)
            )
        );
    }
    
    /**
     * Обработка запроса помощи ИИ для категории
     */
    private function handle_ai_help($chat_id, $category_id, $user_id_telegram) {
        $wp_user_id = $this->get_wp_user_id($user_id_telegram);
        if (!$wp_user_id) {
            $this->send_reply($chat_id, '❌ Пользователь не найден.');
            return false;
        }
        
        // Проверяем, есть ли у пользователя PRO тариф
        $is_pro = get_user_meta($wp_user_id, 'tcm_pro_subscription', true);
        if (!$is_pro) {
            $this->show_pro_required_message($chat_id, $user_id_telegram);
            return false;
        }
        
        $category_id = intval($category_id);
        $category = get_category($category_id);
        if (!$category) {
            $this->send_reply($chat_id, '❌ Категория не найдена.');
            return false;
        }
        
        // Проверяем кэш перед запросом к API
        $level_name_prepositional = $this->get_category_level_name($category_id, 'prepositional');
        $cache_key = 'tcm_ai_help_' . $wp_user_id . '_' . $category_id;
        $cached_response = get_transient($cache_key);
        
        if ($cached_response !== false) {
            // Используем кэш - ответ будет мгновенным
            $ai_response = $cached_response;
        } else {
            // Нет кэша - нужно сделать запрос к API
            // Сохраняем состояние запроса помощи ИИ для продолжения после ответа на вопрос
            update_user_meta($wp_user_id, 'tcm_pending_ai_help', array(
                'category_id' => $category_id,
                'category_name' => $category->name,
                'level_name' => $level_name_prepositional
            ));
            
            // Показываем предупреждение и вопрос анкеты вместе
            $has_question = $this->show_questionnaire_question_for_ai_help($chat_id, $user_id_telegram, $wp_user_id);
            
            // Если вопросов нет, сразу получаем помощь ИИ
            if (!$has_question) {
                delete_user_meta($wp_user_id, 'tcm_pending_ai_help');
                $this->send_reply($chat_id, '⏳ <b>Ожидание может занять до 1 минуты</b>Формирую помощь от ИИ ассистента...');
                $ai_response = $this->get_deepseek_assistance($category_id, $category->name, $level_name_prepositional, $wp_user_id, true);
                
                if ($ai_response && !empty(trim($ai_response))) {
                    $this->display_ai_response($chat_id, $ai_response, $category, $category_id);
                } else {
                    $api_key = get_option('tcm_deepseek_api_key', '');
                    if (empty($api_key)) {
                        $error_msg = '❌ API ключ DeepSeek не настроен. Обратитесь к администратору.';
                    } else {
                        $error_msg = $this->get_deepseek_error_message();
                        if (!$error_msg) {
                            $error_msg = '❌ Не удалось получить помощь от ИИ ассистента. Возможно, произошла ошибка при обработке ответа от API. Попробуйте позже или обратитесь к администратору.';
                        }
                    }
                    $this->send_reply($chat_id, $error_msg);
                }
            }
            
            return true;
        }
        
        if ($ai_response && !empty(trim($ai_response))) {
            $this->display_ai_response($chat_id, $ai_response, $category, $category_id);
        } else {
            $api_key = get_option('tcm_deepseek_api_key', '');
            if (empty($api_key)) {
                $error_msg = '❌ API ключ DeepSeek не настроен. Обратитесь к администратору.';
            } else {
                // Проверяем детали последней ошибки
                $error_msg = $this->get_deepseek_error_message();
                if (!$error_msg) {
                    $error_msg = '❌ Не удалось получить помощь от ИИ ассистента. Возможно, произошла ошибка при обработке ответа от API. Попробуйте позже или обратитесь к администратору.';
                }
            }
            $this->send_reply($chat_id, $error_msg);
        }
        
        return true;
    }
    
    /**
     * Показ вопроса анкеты при запросе помощи ИИ
     */
    private function show_questionnaire_question_for_ai_help($chat_id, $user_id_telegram, $wp_user_id) {
        // Проверяем разрешение
        $consent_given = get_user_meta($wp_user_id, 'tcm_data_collection_consent', true);
        if (!$consent_given) {
            return false;
        }
        
        // Получаем следующий неотвеченный вопрос
        $next_question = $this->get_next_unanswered_question($wp_user_id);
        
        // Если все вопросы отвечены, не показываем анкету
        if (!$next_question) {
            return false;
        }
        
        // Формируем вопрос
        $question = $next_question['question'];
        $section = $next_question['section'];
        $question_key = $next_question['question_key'];
        $section_key = $next_question['section_key'];
        $question_num = $next_question['question_num'];
        
        // Формируем текст с предупреждением и вопросом анкеты
        $text = "⏳ <b>Ожидание может занять до 1 минуты</b>\n\n";
        $text .= "Пока формируется помощь от ИИ ассистента, вы можете ответить на вопрос анкеты:\n\n";
        $text .= "📋 <b>Вопрос анкеты</b>\n\n";
        $text .= "<b>" . $question['text'] . "</b>\n\n";
        
        // Получаем текущие ответы
        $answers = get_user_meta($wp_user_id, 'tcm_questionnaire_answers', true);
        $current_answer = isset($answers[$section_key][$question_key]) ? $answers[$section_key][$question_key] : null;
        
        $keyboard = array();
        
        // Показываем варианты ответа в виде кнопок
        if (isset($question['options']) && is_array($question['options'])) {
            $options = $question['options'];
            $rows = array();
            $current_row = array();
            
            foreach ($options as $index => $option) {
                $option_num = $index + 1;
                $button_text = $option_num . '. ' . $option;
                
                // Проверяем, выбран ли этот вариант
                if ($question['type'] === 'multiple' && is_array($current_answer) && in_array($option, $current_answer)) {
                    $button_text = '✓ ' . $button_text;
                } elseif ($question['type'] === 'choice' && $current_answer === $option) {
                    $button_text = '✓ ' . $button_text;
                }
                
                $current_row[] = array(
                    'text' => $button_text,
                    'callback_data' => 'questionnaire:select:' . $section_key . ':' . $question_key . ':' . $option_num
                );
                
                // Добавляем по 2 кнопки в ряд
                if (count($current_row) >= 2) {
                    $rows[] = $current_row;
                    $current_row = array();
                }
            }
            
            // Добавляем оставшиеся кнопки
            if (!empty($current_row)) {
                $rows[] = $current_row;
            }
            
            $keyboard = array_merge($keyboard, $rows);
            
            // Добавляем кнопку "Свой вариант" если тип вопроса choice
            if ($question['type'] === 'choice') {
                $keyboard[] = array(
                    array('text' => '✏️ Свой вариант', 'callback_data' => 'questionnaire:custom:' . $section_key . ':' . $question_key)
                );
            }
            
            // Для множественного выбора добавляем кнопку "Завершить выбор"
            if ($question['type'] === 'multiple') {
                $keyboard[] = array(
                    array('text' => '✅ Завершить выбор', 'callback_data' => 'questionnaire:finish:' . $section_key . ':' . $question_key)
                );
            }
        }
        
        // Добавляем служебные кнопки
        $service_row = array(
            array('text' => '⏭️ Пропустить', 'callback_data' => 'skip_question:' . $section_key . ':' . $question_key),
            array('text' => '➡️ Продолжить без ответа', 'callback_data' => 'continue_ai_help_without_answer')
        );
        $keyboard[] = $service_row;
        
        // Сохраняем текущий вопрос для обработки ответа
        update_user_meta($wp_user_id, 'tcm_questionnaire_current_question', array(
            'section_key' => $section_key,
            'question_key' => $question_key,
            'question_num' => $question_num
        ));
        
        $this->send_reply_with_keyboard($chat_id, $text, $keyboard);
        
        return true;
    }
    
    /**
     * Получение помощи ИИ после ответа на вопрос анкеты
     */
    private function get_ai_help_after_questionnaire($chat_id, $category_id, $category_name, $level_name, $wp_user_id) {
        // Показываем предупреждение о времени ожидания
        $this->send_reply($chat_id, "⏳ <b>Ожидание может занять до 1 минуты</b>\nФормирую помощь от ИИ ассистента...");
        $ai_response = $this->get_deepseek_assistance($category_id, $category_name, $level_name, $wp_user_id, true);
        
        if ($ai_response && !empty(trim($ai_response))) {
            $category = get_category($category_id);
            if ($category) {
                $this->display_ai_response($chat_id, $ai_response, $category, $category_id);
            }
        } else {
            $api_key = get_option('tcm_deepseek_api_key', '');
            if (empty($api_key)) {
                $error_msg = '❌ API ключ DeepSeek не настроен. Обратитесь к администратору.';
            } else {
                $error_msg = $this->get_deepseek_error_message();
                if (!$error_msg) {
                    $error_msg = '❌ Не удалось получить помощь от ИИ ассистента. Возможно, произошла ошибка при обработке ответа от API. Попробуйте позже или обратитесь к администратору.';
                }
            }
            $this->send_reply($chat_id, $error_msg);
        }
    }
    
    /**
     * Отображение ответа ИИ
     */
    private function display_ai_response($chat_id, $ai_response, $category, $category_id) {
        // Telegram имеет лимит 4096 символов на сообщение
        $max_length = 4000; // Оставляем запас для заголовка и форматирования
        $header = "🤖 <b>Помощь ИИ ассистента</b>\n\n📂 <b>" . esc_html($category->name) . "</b>\n\n";
        $header_length = mb_strlen(strip_tags($header));
        $available_length = $max_length - $header_length;
        
        // Если ответ слишком длинный, разбиваем на части
        if (mb_strlen($ai_response) > $available_length) {
            // Отправляем первую часть с заголовком
            $first_part = mb_substr($ai_response, 0, $available_length);
            $text = $header . $first_part;
            
            $keyboard = array(
                array(
                    array('text' => '🔄 Обновить ответ ИИ', 'callback_data' => 'ai_help_refresh:' . $category_id),
                    array('text' => '🗑️ Очистить историю', 'callback_data' => 'ai_help_clear_history')
                ),
                array(
                    array('text' => '⬅️ Назад', 'callback_data' => 'category:' . ($category->parent > 0 ? $category->parent : 0))
                )
            );
            
            $this->send_reply_with_keyboard($chat_id, $text, $keyboard);
            
            // Отправляем остальные части
            $remaining = mb_substr($ai_response, $available_length);
            $chunk_size = $max_length - 50; // Запас для нумерации
            $part_num = 2;
            
            while (mb_strlen($remaining) > 0) {
                $chunk = mb_substr($remaining, 0, $chunk_size);
                $remaining = mb_substr($remaining, $chunk_size);
                
                $chunk_text = "📄 <b>Продолжение (часть " . $part_num . ")</b>\n\n" . $chunk;
                $this->send_reply($chat_id, $chunk_text);
                $part_num++;
                
                // Небольшая задержка между сообщениями
                usleep(300000); // 0.3 секунды
            }
        } else {
            // Обычный случай - ответ помещается в одно сообщение
            $text = $header . $ai_response;
            
            $keyboard = array(
                array(
                    array('text' => '🔄 Обновить ответ ИИ', 'callback_data' => 'ai_help_refresh:' . $category_id),
                    array('text' => '🗑️ Очистить историю', 'callback_data' => 'ai_help_clear_history')
                ),
                array(
                    array('text' => '⬅️ Назад', 'callback_data' => 'category:' . ($category->parent > 0 ? $category->parent : 0))
                )
            );
            
            $this->send_reply_with_keyboard($chat_id, $text, $keyboard);
        }
    }
    
    /**
     * Обновление ответа ИИ для категории
     */
    private function handle_ai_help_refresh($chat_id, $category_id, $user_id_telegram) {
        $wp_user_id = $this->get_wp_user_id($user_id_telegram);
        if (!$wp_user_id) {
            $this->send_reply($chat_id, '❌ Пользователь не найден.');
            return false;
        }
        
        // Проверяем, есть ли у пользователя PRO тариф
        $is_pro = get_user_meta($wp_user_id, 'tcm_pro_subscription', true);
        if (!$is_pro) {
            $this->show_pro_required_message($chat_id, $user_id_telegram);
            return false;
        }
        
        $category_id = intval($category_id);
        $category = get_category($category_id);
        if (!$category) {
            $this->send_reply($chat_id, '❌ Категория не найдена.');
            return false;
        }
        
        // Очищаем кэш перед формированием нового запроса
        $cache_key = 'tcm_ai_help_' . $wp_user_id . '_' . $category_id;
        delete_transient($cache_key);
        
        // Формируем новый запрос к DeepSeek (без использования кэша)
        $this->send_reply($chat_id, '⏳ Обновляю помощь от ИИ ассистента... Это может занять несколько секунд.');
        
        $level_name_prepositional = $this->get_category_level_name($category_id, 'prepositional');
        $ai_response = $this->get_deepseek_assistance($category_id, $category->name, $level_name_prepositional, $wp_user_id, false);
        
        if ($ai_response && !empty(trim($ai_response))) {
            // Telegram имеет лимит 4096 символов на сообщение
            $max_length = 4000; // Оставляем запас для заголовка и форматирования
            $header = "🤖 <b>Помощь ИИ ассистента (обновлено)</b>\n\n📂 <b>" . esc_html($category->name) . "</b>\n\n";
            $header_length = mb_strlen(strip_tags($header));
            $available_length = $max_length - $header_length;
            
            // Если ответ слишком длинный, разбиваем на части
            if (mb_strlen($ai_response) > $available_length) {
                // Отправляем первую часть с заголовком
                $first_part = mb_substr($ai_response, 0, $available_length);
                $text = $header . $first_part;
                
                $keyboard = array(
                    array(
                        array('text' => '🔄 Обновить ответ ИИ', 'callback_data' => 'ai_help_refresh:' . $category_id),
                        array('text' => '🗑️ Очистить историю', 'callback_data' => 'ai_help_clear_history')
                    ),
                    array(
                        array('text' => '⬅️ Назад', 'callback_data' => 'category:' . ($category->parent > 0 ? $category->parent : 0))
                    )
                );
                
                $this->send_reply_with_keyboard($chat_id, $text, $keyboard);
                
                // Отправляем остальные части
                $remaining = mb_substr($ai_response, $available_length);
                $chunk_size = $max_length - 50; // Запас для нумерации
                $part_num = 2;
                
                while (mb_strlen($remaining) > 0) {
                    $chunk = mb_substr($remaining, 0, $chunk_size);
                    $remaining = mb_substr($remaining, $chunk_size);
                    
                    $chunk_text = "📄 <b>Продолжение (часть " . $part_num . ")</b>\n\n" . $chunk;
                    $this->send_reply($chat_id, $chunk_text);
                    $part_num++;
                    
                    // Небольшая задержка между сообщениями
                    usleep(300000); // 0.3 секунды
                }
            } else {
                // Обычный случай - ответ помещается в одно сообщение
                $text = $header . $ai_response;
                
                $keyboard = array(
                    array(
                        array('text' => '🔄 Обновить ответ ИИ', 'callback_data' => 'ai_help_refresh:' . $category_id),
                        array('text' => '🗑️ Очистить историю', 'callback_data' => 'ai_help_clear_history')
                    ),
                    array(
                        array('text' => '⬅️ Назад', 'callback_data' => 'category:' . ($category->parent > 0 ? $category->parent : 0))
                    )
                );
                
                $this->send_reply_with_keyboard($chat_id, $text, $keyboard);
            }
        } else {
            $api_key = get_option('tcm_deepseek_api_key', '');
            if (empty($api_key)) {
                $error_msg = '❌ API ключ DeepSeek не настроен. Обратитесь к администратору.';
            } else {
                // Проверяем детали последней ошибки
                $error_msg = $this->get_deepseek_error_message();
                if (!$error_msg) {
                    $error_msg = '❌ Не удалось обновить помощь от ИИ ассистента. Возможно, произошла ошибка при обработке ответа от API. Попробуйте позже или обратитесь к администратору.';
                }
            }
            $this->send_reply($chat_id, $error_msg);
        }
        
        return true;
    }
    
    /**
     * Обработка очистки истории диалога
     */
    private function handle_ai_help_clear_history($chat_id, $user_id_telegram) {
        $wp_user_id = $this->get_wp_user_id($user_id_telegram);
        if (!$wp_user_id) {
            $this->send_reply($chat_id, '❌ Пользователь не найден.');
            return false;
        }
        
        // Проверяем, есть ли у пользователя PRO тариф
        $is_pro = get_user_meta($wp_user_id, 'tcm_pro_subscription', true);
        if (!$is_pro) {
            $this->show_pro_required_message($chat_id, $user_id_telegram);
            return false;
        }
        
        // Очищаем историю
        $this->clear_conversation_history($wp_user_id);
        
        $this->send_reply($chat_id, '✅ История диалога с ИИ ассистентом очищена. Следующий запрос будет без учета предыдущих сообщений.');
        
        return true;
    }
    
    /**
     * Показ сообщения о необходимости PRO тарифа с кнопкой "Подробнее"
     */
    private function show_pro_required_message($chat_id, $user_id_telegram) {
        $text = "❌ Эта функция доступна только для пользователей с тарифом <b>PRO</b>.\n\n";
        $text .= "Нажмите кнопку ниже, чтобы узнать больше о возможностях тарифа PRO.";
        
        $keyboard = array(
            array(
                array('text' => 'ℹ️ Подробнее о PRO', 'callback_data' => 'pro_details')
            )
        );
        
        $this->send_reply_with_keyboard($chat_id, $text, $keyboard);
    }
    
    /**
     * Обработка запроса подробной информации о PRO тарифе
     */
    private function handle_pro_details($chat_id, $user_id_telegram) {
        $wp_user_id = $this->get_wp_user_id($user_id_telegram);
        if (!$wp_user_id) {
            $this->send_reply($chat_id, '❌ Пользователь не найден. Пожалуйста, зарегистрируйтесь через /register или /link');
            return false;
        }
        
        // Проверяем, есть ли уже PRO тариф
        $is_pro = get_user_meta($wp_user_id, 'tcm_pro_subscription', true);
        if ($is_pro) {
            $this->send_reply($chat_id, '✅ У вас уже активирован тариф PRO!');
            return true;
        }
        
        // Получаем цену
        $payment_class = new TCM_Payment();
        $price = $payment_class->get_pro_price(30);
        
        $text = "⭐ <b>Тариф PRO</b>\n\n";
        $text .= "Получите доступ к расширенным возможностям программы 12 шагов:\n\n";
        $text .= "🤖 <b>ИИ помощник в работе по Шагам</b>\n";
        $text .= "Для каждой выбранной точки вы получите:\n\n";
        $text .= "📚 <b>Выдержки из одобренной литературы</b>\n";
        $text .= "Релевантные цитаты и выдержки из проверенных источников, специально подобранные для текущей точки вашего пути.\n\n";
        $text .= "👥 <b>Примеры из жизни других зависимых</b>\n";
        $text .= "Реальные истории людей, которые прошли через похожие ситуации в этой конкретной точке, чтобы помочь вам понять, как применить программу в действии.\n\n";
        $text .= "🎯 <b>Практические рекомендации по применению программы</b>\n";
        $text .= "Конкретные советы и пошаговые инструкции, адаптированные именно для текущей точки, которые помогут вам продвинуться дальше.\n\n";
        $text .= "💡 <b>Персонализированные советы по текущему вопросу/Шагу</b>\n";
        $text .= "Индивидуальные рекомендации, учитывающие ваш уникальный путь и обстоятельства, специально для этой точки.\n\n";
        $text .= "📊 <b>Дополнительные функции:</b>\n";
        $text .= "• Приоритетная поддержка 24/7\n";
        $text .= "• Расширенная аналитика вашего прогресса\n";
        $text .= "• Персональные рекомендации на основе ваших ответов\n";
        $text .= "• Доступ к эксклюзивным материалам и ресурсам\n\n";
        $text .= "💰 <b>Стоимость:</b> " . number_format($price, 2, ',', ' ') . " ₽ за 30 дней\n\n";
        $text .= "Подключите тариф PRO и получите полный доступ ко всем возможностям для каждой точки вашего пути!";
        
        // Получаем Telegram ID пользователя
        $telegram_id = get_user_meta($wp_user_id, 'tcm_telegram_id', true);
        if (empty($telegram_id)) {
            // Пытаемся получить из текущего контекста
            $telegram_id = $user_id_telegram;
        }
        
        // Получаем ссылку на оплату с Telegram ID
        $payment_url = $payment_class->get_payment_url($wp_user_id, 30, $telegram_id);
        
        $log_enabled = defined('WP_DEBUG') && WP_DEBUG;
        if ($log_enabled) {
            error_log('TCM: handle_pro_details - payment_url: ' . ($payment_url ? $payment_url : 'empty'));
            error_log('TCM: handle_pro_details - payment_method: ' . get_option('tcm_payment_method', 'manual'));
        }
        
        $keyboard = array();
        
        if ($payment_url && !empty($payment_url)) {
            $keyboard[] = array(
                array('text' => '💳 Подключить PRO', 'url' => $payment_url)
            );
        } else {
            // Если оплата не настроена, показываем сообщение
            $payment_method = get_option('tcm_payment_method', 'manual');
            if ($payment_method === 'yookassa') {
                $text .= "\n\n⚠️ Платежная система ЮKassa не настроена. Обратитесь к администратору.";
            } else {
                $text .= "\n\n⚠️ Для подключения тарифа PRO обратитесь к администратору.";
            }
            $keyboard[] = array(
                array('text' => '📞 Связаться с администратором', 'callback_data' => 'support')
            );
        }
        
        $keyboard[] = array(
            array('text' => '⬅️ Назад', 'callback_data' => 'main_menu')
        );
        
        if ($log_enabled) {
            error_log('TCM: handle_pro_details - keyboard: ' . print_r($keyboard, true));
        }
        
        $this->send_reply_with_keyboard($chat_id, $text, $keyboard);
        
        return true;
    }
    
    /**
     * Обработка просмотра записей
     */
    private function handle_view_posts($chat_id, $action, $user_id_telegram) {
        $wp_user_id = $this->get_wp_user_id($user_id_telegram);
        if (!$wp_user_id) {
            $this->send_reply($chat_id, '❌ Пользователь не найден. Пожалуйста, зарегистрируйтесь через /register или /link');
            return false;
        }
        
        if ($action === 'menu') {
            return $this->show_view_posts_menu($chat_id, $wp_user_id);
        }
        
        return false;
    }
    
    /**
     * Показ меню просмотра записей
     */
    private function show_view_posts_menu($chat_id, $wp_user_id) {
        // Получаем текущие выбранные категории
        $user_categories = get_option('tcm_user_categories', array());
        $chat_categories = get_option('tcm_chat_categories', array());
        $chat_id_str = (string)$chat_id;
        
        $current_category_id = 0;
        if (isset($user_categories[$chat_id_str])) {
            $current_category_id = intval($user_categories[$chat_id_str]);
        } elseif (isset($chat_categories[$chat_id_str])) {
            $current_category_id = intval($chat_categories[$chat_id_str]);
        }
        
        $keyboard = array();
        
        // Кнопка "Последняя запись"
        $keyboard[] = array(
            array('text' => '📄 Последняя запись', 'callback_data' => 'view_last_post')
        );
        
        // Кнопки для текущих Шага, Главы, Точки (если есть выбранная категория)
        if ($current_category_id > 0) {
            $category = get_category($current_category_id);
            if ($category) {
                // Определяем уровни категорий
                $step_id = $this->get_category_at_level($current_category_id, 0);
                $chapter_id = $this->get_category_at_level($current_category_id, 1);
                $point_id = $this->get_category_at_level($current_category_id, 2);
                
                if ($step_id) {
                    $step = get_category($step_id);
                    $posts_count = $this->get_category_posts_count($step_id, $wp_user_id);
                    $step_name = '📚 Текущий Шаг: ' . esc_html($step->name);
                    if ($posts_count > 0) {
                        $step_name = '(' . $posts_count . ') ' . $step_name;
                    }
                    $keyboard[] = array(
                        array('text' => $step_name, 'callback_data' => 'view_current_step')
                    );
                }
                
                if ($chapter_id) {
                    $chapter = get_category($chapter_id);
                    $posts_count = $this->get_category_posts_count($chapter_id, $wp_user_id);
                    $chapter_name = '📖 Текущая Глава: ' . esc_html($chapter->name);
                    if ($posts_count > 0) {
                        $chapter_name = '(' . $posts_count . ') ' . $chapter_name;
                    }
                    $keyboard[] = array(
                        array('text' => $chapter_name, 'callback_data' => 'view_current_chapter')
                    );
                }
                
                if ($point_id) {
                    $point = get_category($point_id);
                    $posts_count = $this->get_category_posts_count($point_id, $wp_user_id);
                    $point_name = '📍 Текущая Точка: ' . esc_html($point->name);
                    if ($posts_count > 0) {
                        $point_name = '(' . $posts_count . ') ' . $point_name;
                    }
                    $keyboard[] = array(
                        array('text' => $point_name, 'callback_data' => 'view_current_point')
                    );
                    
                    // Проверяем, есть ли следующая точка
                    $next_point = $this->get_next_point($point_id);
                    if ($next_point) {
                        $next_point_name_short = mb_strlen($next_point['name']) > 30 ? mb_substr($next_point['name'], 0, 27) . '...' : $next_point['name'];
                        $keyboard[] = array(
                            array('text' => '➡️ Перейти в следующую точку: ' . $next_point_name_short, 'callback_data' => 'go_to_next_point:' . $next_point['id'])
                        );
                    }
                }
            }
        }
        
        // Кнопка "Выбрать из других рубрик"
        $keyboard[] = array(
            array('text' => '🔍 Выбрать из других рубрик', 'callback_data' => 'custom_category:menu')
        );
        
        // Кнопка "Назад"
        $keyboard[] = array(
            array('text' => '🔙 Назад', 'callback_data' => 'menu')
        );
        
        $text = "📝 <b>Мои записи</b>\n\n" .
                "Выберите, какие записи вы хотите просмотреть:";
        
        return $this->send_reply_with_keyboard($chat_id, $text, $keyboard);
    }
    
    /**
     * Получение категории на определенном уровне иерархии
     */
    private function get_category_at_level($category_id, $target_level) {
        $category = get_category($category_id);
        if (!$category) {
            return 0;
        }
        
        // Определяем текущий уровень
        $level = 0;
        $current = $category;
        while ($current && $current->parent > 0) {
            $level++;
            $current = get_category($current->parent);
            if (!$current) {
                break;
            }
        }
        
        // Если текущий уровень = целевому, возвращаем эту категорию
        if ($level == $target_level) {
            return $category_id;
        }
        
        // Если текущий уровень выше целевого, поднимаемся вверх
        if ($level > $target_level) {
            $current = $category;
            $steps_up = $level - $target_level;
            for ($i = 0; $i < $steps_up; $i++) {
                if ($current && $current->parent > 0) {
                    $current = get_category($current->parent);
                    if (!$current) {
                        return 0;
                    }
                } else {
                    return 0;
                }
            }
            return $current->term_id;
        }
        
        return 0;
    }
    
    /**
     * Просмотр последней записи
     */
    private function handle_view_last_post($chat_id, $user_id_telegram) {
        $wp_user_id = $this->get_wp_user_id($user_id_telegram);
        if (!$wp_user_id) {
            $this->send_reply($chat_id, '❌ Пользователь не найден.');
            return false;
        }
        
        // Получаем последнюю запись пользователя
        $posts = get_posts(array(
            'author' => $wp_user_id,
            'post_type' => 'post',
            'post_status' => array('publish', 'draft'),
            'numberposts' => 1,
            'orderby' => 'date',
            'order' => 'DESC'
        ));
        
        if (empty($posts)) {
            $this->send_reply($chat_id, '📝 У вас пока нет записей.');
            return true;
        }
        
        $post = $posts[0];
        $category = get_the_category($post->ID);
        $category_name = !empty($category) ? $category[0]->name : 'Без категории';
        $post_url = get_permalink($post->ID);
        
        $text = "📄 <b>Последняя запись</b>\n\n" .
                "📂 Категория: " . esc_html($category_name) . "\n" .
                "📅 Дата: " . date('d.m.Y H:i', strtotime($post->post_date)) . "\n\n" .
                "<b>Содержание:</b>\n" .
                esc_html($post->post_content) . "\n\n" .
                "🔗 <a href=\"" . esc_url($post_url) . "\">Открыть на сайте</a>";
        
        $keyboard = array(
            array(
                array('text' => '✏️ Редактировать запись', 'callback_data' => 'edit_post:' . $post->ID)
            )
        );
        
        // Проверяем, есть ли следующая точка для категории записи
        if (!empty($category) && isset($category[0])) {
            $category_id = $category[0]->term_id;
            $next_point = $this->get_next_point($category_id);
            if ($next_point) {
                $next_point_name_short = mb_strlen($next_point['name']) > 30 ? mb_substr($next_point['name'], 0, 27) . '...' : $next_point['name'];
                $keyboard[] = array(
                    array('text' => '➡️ Перейти в следующую точку: ' . $next_point_name_short, 'callback_data' => 'go_to_next_point:' . $next_point['id'])
                );
            }
        }
        
        $keyboard[] = array(
            array('text' => '🔙 Назад', 'callback_data' => 'view_posts:menu')
        );
        
        return $this->send_reply_with_keyboard($chat_id, $text, $keyboard);
    }
    
    /**
     * Просмотр записей текущей категории (Шаг, Глава или Точка)
     */
    private function handle_view_current_category($chat_id, $user_id_telegram, $type) {
        $wp_user_id = $this->get_wp_user_id($user_id_telegram);
        if (!$wp_user_id) {
            $this->send_reply($chat_id, '❌ Пользователь не найден.');
            return false;
        }
        
        // Получаем текущую выбранную категорию
        $user_categories = get_option('tcm_user_categories', array());
        $chat_categories = get_option('tcm_chat_categories', array());
        $chat_id_str = (string)$chat_id;
        
        $current_category_id = 0;
        if (isset($user_categories[$chat_id_str])) {
            $current_category_id = intval($user_categories[$chat_id_str]);
        } elseif (isset($chat_categories[$chat_id_str])) {
            $current_category_id = intval($chat_categories[$chat_id_str]);
        }
        
        if ($current_category_id <= 0) {
            $this->send_reply($chat_id, '❌ Категория не выбрана. Выберите категорию через меню "Выбор Шага".');
            return false;
        }
        
        // Определяем категорию нужного уровня
        $target_level = 0;
        $level_name = '';
        
        switch ($type) {
            case 'step':
                $target_level = 0;
                $level_name = 'Шаг';
                break;
            case 'chapter':
                $target_level = 1;
                $level_name = 'Глава';
                break;
            case 'point':
                $target_level = 2;
                $level_name = 'Точка';
                break;
        }
        
        $category_id = $this->get_category_at_level($current_category_id, $target_level);
        
        if (!$category_id) {
            $this->send_reply($chat_id, "❌ Не удалось определить текущий {$level_name}.");
            return false;
        }
        
        $category = get_category($category_id);
        if (!$category) {
            $this->send_reply($chat_id, "❌ Категория не найдена.");
            return false;
        }
        
        // Получаем все записи пользователя в этой категории и её дочерних
        $category_ids = array($category_id);
        $children = get_categories(array('parent' => $category_id));
        foreach ($children as $child) {
            $category_ids[] = $child->term_id;
            // Если это Глава, получаем и её дочерние Точки
            if ($type === 'chapter') {
                $points = get_categories(array('parent' => $child->term_id));
                foreach ($points as $point) {
                    $category_ids[] = $point->term_id;
                }
            }
        }
        
        // Для Шага собираем все записи из всех дочерних категорий (Глав и Точек)
        if ($type === 'step') {
            // Получаем все Главы
            $chapters = get_categories(array('parent' => $category_id));
            foreach ($chapters as $chapter) {
                $category_ids[] = $chapter->term_id;
                // Получаем все Точки каждой Главы
                $points = get_categories(array('parent' => $chapter->term_id));
                foreach ($points as $point) {
                    $category_ids[] = $point->term_id;
                }
            }
        }
        
        // Получаем все записи (без ограничения для подсчета)
        $all_posts = get_posts(array(
            'author' => $wp_user_id,
            'category__in' => $category_ids,
            'post_type' => 'post',
            'post_status' => array('publish', 'draft'),
            'numberposts' => -1,
            'fields' => 'ids'
        ));
        
        if (empty($all_posts)) {
            $text = "📝 У вас пока нет записей в категории <b>" . esc_html($category->name) . "</b>.";
            $keyboard = array(
                array(
                    array('text' => '🔙 Назад', 'callback_data' => 'view_posts:menu')
                )
            );
            return $this->send_reply_with_keyboard($chat_id, $text, $keyboard);
        }
        
        // Показываем информацию о категории и кнопку "Показать записи"
        $text = "📚 <b>{$level_name}: " . esc_html($category->name) . "</b>\n\n";
        $text .= "Найдено записей: " . count($all_posts) . "\n\n";
        
        $keyboard = array();
        $keyboard[] = array(
            array('text' => '📄 Показать записи', 'callback_data' => 'show_posts:' . $category_id)
        );
        $keyboard[] = array(
            array('text' => '🔙 Назад', 'callback_data' => 'view_posts:menu')
        );
        
        return $this->send_reply_with_keyboard($chat_id, $text, $keyboard);
    }
    
    /**
     * Просмотр конкретной записи
     */
    private function handle_view_post($chat_id, $post_id, $user_id_telegram) {
        $wp_user_id = $this->get_wp_user_id($user_id_telegram);
        if (!$wp_user_id) {
            $this->send_reply($chat_id, '❌ Пользователь не найден.');
            return false;
        }
        
        $post = get_post($post_id);
        if (!$post || $post->post_author != $wp_user_id) {
            $this->send_reply($chat_id, '❌ Запись не найдена или у вас нет доступа к ней.');
            return false;
        }
        
        $category = get_the_category($post->ID);
        $category_name = !empty($category) ? $category[0]->name : 'Без категории';
        $post_url = get_permalink($post->ID);
        
        $text = "📄 <b>" . esc_html($post->post_title) . "</b>\n\n" .
                "📂 Категория: " . esc_html($category_name) . "\n" .
                "📅 Дата: " . date('d.m.Y H:i', strtotime($post->post_date)) . "\n" .
                "📊 Статус: " . ($post->post_status === 'publish' ? 'Опубликовано' : 'Черновик') . "\n\n" .
                "<b>Содержание:</b>\n" .
                esc_html($post->post_content) . "\n\n" .
                "🔗 <a href=\"" . esc_url($post_url) . "\">Открыть на сайте</a>";
        
        $keyboard = array(
            array(
                array('text' => '✏️ Редактировать запись', 'callback_data' => 'edit_post:' . $post->ID)
            ),
            array(
                array('text' => '🔙 Назад', 'callback_data' => 'view_posts:menu')
            )
        );
        
        return $this->send_reply_with_keyboard($chat_id, $text, $keyboard);
    }
    
    /**
     * Начало редактирования записи
     */
    private function handle_edit_post($chat_id, $post_id, $user_id_telegram) {
        $wp_user_id = $this->get_wp_user_id($user_id_telegram);
        if (!$wp_user_id) {
            $this->send_reply($chat_id, '❌ Пользователь не найден.');
            return false;
        }
        
        $post_id = intval($post_id);
        $post = get_post($post_id);
        
        if (!$post || $post->post_author != $wp_user_id) {
            $this->send_reply($chat_id, '❌ Запись не найдена или у вас нет доступа к ней.');
            return false;
        }
        
        // Сохраняем состояние редактирования
        update_user_meta($wp_user_id, 'tcm_editing_post_id', $post_id);
        
        $category = get_the_category($post->ID);
        $category_name = !empty($category) ? $category[0]->name : 'Без категории';
        
        // Первое сообщение - инструкция с информацией о записи
        $instruction_text = "✏️ <b>Редактирование записи</b>\n\n" .
                "📂 Категория: " . esc_html($category_name) . "\n" .
                "📅 Дата создания: " . date('d.m.Y H:i', strtotime($post->post_date)) . "\n\n" .
                "📝 <b>Отправьте новый текст для записи.</b>\n\n" .
                "💡 Для отмены редактирования отправьте команду /cancel";
        
        $keyboard = array(
            array(
                array('text' => '❌ Отменить редактирование', 'callback_data' => 'cancel_edit_post')
            )
        );
        
        // Отправляем инструкцию с клавиатурой
        $this->send_reply_with_keyboard($chat_id, $instruction_text, $keyboard);
        
        // Второе сообщение - только текст записи для копирования
        $post_content = esc_html($post->post_content);
        $text_message = "📋 <b>Текст записи для копирования:</b>\n\n" . $post_content;
        
        // Отправляем текст записи отдельным сообщением
        $this->send_reply($chat_id, $text_message);
        
        return true;
    }
    
    /**
     * Сохранение отредактированной записи
     */
    private function save_edited_post($chat_id, $user_id_telegram, $wp_user_id, $post_id, $new_content) {
        $log_enabled = defined('WP_DEBUG') && WP_DEBUG;
        
        $post_id = intval($post_id);
        $post = get_post($post_id);
        
        if (!$post || $post->post_author != $wp_user_id) {
            // Удаляем состояние редактирования
            delete_user_meta($wp_user_id, 'tcm_editing_post_id');
            $this->send_reply($chat_id, '❌ Запись не найдена или у вас нет доступа к ней.');
            return false;
        }
        
        // Обновляем запись
        $updated_post = array(
            'ID' => $post_id,
            'post_content' => sanitize_textarea_field($new_content)
        );
        
        $result = wp_update_post($updated_post);
        
        if (is_wp_error($result)) {
            if ($log_enabled) {
                error_log('TCM: Error updating post: ' . $result->get_error_message());
            }
            $this->send_reply($chat_id, '❌ Ошибка при сохранении записи: ' . $result->get_error_message());
            delete_user_meta($wp_user_id, 'tcm_editing_post_id');
            return false;
        }
        
        // Удаляем состояние редактирования
        delete_user_meta($wp_user_id, 'tcm_editing_post_id');
        
        // Получаем ссылку на запись
        $post_url = get_permalink($post_id);
        if (!$post_url) {
            $post_url = home_url('/?p=' . $post_id);
        }
        
        $category = get_the_category($post_id);
        $category_name = !empty($category) ? $category[0]->name : 'Без категории';
        
        $text = "✅ <b>Запись успешно обновлена!</b>\n\n" .
                "📂 Категория: " . esc_html($category_name) . "\n" .
                "📅 Дата: " . date('d.m.Y H:i', strtotime($post->post_date)) . "\n\n" .
                "<b>Обновленное содержание:</b>\n" .
                esc_html($new_content) . "\n\n" .
                "🔗 <a href=\"" . esc_url($post_url) . "\">Открыть запись на сайте</a>";
        
        $keyboard = array(
            array(
                array('text' => '✏️ Редактировать снова', 'callback_data' => 'edit_post:' . $post_id)
            ),
            array(
                array('text' => '🔙 Назад', 'callback_data' => 'view_posts:menu')
            )
        );
        
        return $this->send_reply_with_keyboard($chat_id, $text, $keyboard);
    }
    
    /**
     * Отмена редактирования записи
     */
    private function handle_cancel_edit_post($chat_id, $user_id_telegram) {
        $wp_user_id = $this->get_wp_user_id($user_id_telegram);
        if (!$wp_user_id) {
            $this->send_reply($chat_id, '❌ Пользователь не найден.');
            return false;
        }
        
        // Удаляем состояние редактирования
        delete_user_meta($wp_user_id, 'tcm_editing_post_id');
        
        $text = "❌ <b>Редактирование отменено</b>\n\n" .
                "Запись не была изменена.";
        
        $keyboard = array(
            array(
                array('text' => '🔙 Назад', 'callback_data' => 'view_posts:menu')
            )
        );
        
        return $this->send_reply_with_keyboard($chat_id, $text, $keyboard);
    }
    
    /**
     * Показ записей выбранной категории
     */
    private function handle_show_posts($chat_id, $category_id, $user_id_telegram) {
        $wp_user_id = $this->get_wp_user_id($user_id_telegram);
        if (!$wp_user_id) {
            $this->send_reply($chat_id, '❌ Пользователь не найден.');
            return false;
        }
        
        $category_id = intval($category_id);
        $category = get_category($category_id);
        
        if (!$category) {
            $this->send_reply($chat_id, '❌ Категория не найдена.');
            return false;
        }
        
        // Получаем все записи пользователя в этой категории и её дочерних
        $category_ids = array($category_id);
        $children = get_categories(array('parent' => $category_id));
        foreach ($children as $child) {
            $category_ids[] = $child->term_id;
            // Получаем и дочерние категории дочерних
            $grandchildren = get_categories(array('parent' => $child->term_id));
            foreach ($grandchildren as $grandchild) {
                $category_ids[] = $grandchild->term_id;
            }
        }
        
        $posts = get_posts(array(
            'author' => $wp_user_id,
            'category__in' => $category_ids,
            'post_type' => 'post',
            'post_status' => array('publish', 'draft'),
            'numberposts' => -1,
            'orderby' => 'date',
            'order' => 'ASC'
        ));
        
        if (empty($posts)) {
            $text = "📝 У вас пока нет записей в категории <b>" . esc_html($category->name) . "</b>.";
            $keyboard = array(
                array(
                    array('text' => '🔙 Назад', 'callback_data' => 'view_posts:menu')
                )
            );
            return $this->send_reply_with_keyboard($chat_id, $text, $keyboard);
        }
        
        // Формируем текст со всеми записями
        $text = "📝 <b>Записи: " . esc_html($category->name) . "</b>\n\n";
        $text .= "Всего записей: " . count($posts) . "\n\n";
        $text .= "━━━━━━━━━━━━━━━━━━━━\n\n";
        
        foreach ($posts as $index => $post) {
            $post_categories = get_the_category($post->ID);
            $post_category_name = !empty($post_categories) ? $post_categories[0]->name : 'Без категории';
            $post_date = date('d.m.Y H:i', strtotime($post->post_date));
            $post_status = $post->post_status === 'publish' ? 'Опубликовано' : 'Черновик';
            
            $text .= "<b>" . ($index + 1) . ". " . esc_html($post->post_title) . "</b>\n";
            $text .= "📂 Категория: " . esc_html($post_category_name) . "\n";
            $text .= "📅 Дата: {$post_date}\n";
            $text .= "📊 Статус: {$post_status}\n\n";
            $text .= esc_html($post->post_content) . "\n\n";
            $text .= "━━━━━━━━━━━━━━━━━━━━\n\n";
        }
        
        // Проверяем PRO статус
        $payment_class = new TCM_Payment();
        $is_pro = $payment_class->check_pro_subscription($wp_user_id);
        
        // Формируем клавиатуру с кнопками экспорта
        $keyboard = array();
        
        // Добавляем кнопки редактирования для каждой записи (по 2 в ряд)
        $edit_buttons = array();
        foreach ($posts as $index => $post) {
            $button_text = '✏️ ' . ($index + 1);
            $edit_buttons[] = array('text' => $button_text, 'callback_data' => 'edit_post:' . $post->ID);
            
            // Добавляем ряд кнопок каждые 2 записи
            if (count($edit_buttons) >= 2 || $index === count($posts) - 1) {
                $keyboard[] = $edit_buttons;
                $edit_buttons = array();
            }
        }
        
        // Кнопка экспорта в TXT (доступна всем)
        $keyboard[] = array(
            array('text' => '💾 Сохранить в TXT', 'callback_data' => 'export_posts:txt')
        );
        
        // Кнопки экспорта в PDF и DOCX (только для PRO)
        if ($is_pro) {
            $keyboard[] = array(
                array('text' => '📄 Сохранить в PDF', 'callback_data' => 'export_posts:pdf'),
                array('text' => '📝 Сохранить в DOCX', 'callback_data' => 'export_posts:docx')
            );
        } else {
            $keyboard[] = array(
                array('text' => '⭐ PRO 📄 Сохранить в PDF', 'callback_data' => 'export_posts:pdf_pro'),
                array('text' => '⭐ PRO 📝 Сохранить в DOCX', 'callback_data' => 'export_posts:docx_pro')
            );
        }
        
        // Кнопка "Назад"
        $keyboard[] = array(
            array('text' => '🔙 Назад', 'callback_data' => 'view_posts:menu')
        );
        
        // Если текст слишком длинный, разбиваем на несколько сообщений
        $max_length = 4000;
        if (strlen($text) > $max_length) {
            $first_part = substr($text, 0, $max_length);
            $last_newline = strrpos($first_part, "\n\n");
            if ($last_newline !== false) {
                $first_part = substr($text, 0, $last_newline);
            }
            $this->send_reply($chat_id, $first_part);
            
            $remaining = substr($text, strlen($first_part));
            while (strlen($remaining) > $max_length) {
                $part = substr($remaining, 0, $max_length);
                $last_newline = strrpos($part, "\n\n");
                if ($last_newline !== false) {
                    $part = substr($remaining, 0, $last_newline);
                }
                $this->send_reply($chat_id, $part);
                $remaining = substr($remaining, strlen($part));
            }
            
            if (!empty($remaining)) {
                return $this->send_reply_with_keyboard($chat_id, $remaining, $keyboard);
            } else {
                return $this->send_reply_with_keyboard($chat_id, "💾 <b>Экспорт записей</b>\n\nВыберите формат для сохранения:", $keyboard);
            }
        }
        
        return $this->send_reply_with_keyboard($chat_id, $text, $keyboard);
    }
    
    /**
     * Обработка экспорта записей
     */
    private function handle_export_posts($chat_id, $format_and_category, $user_id_telegram) {
        $wp_user_id = $this->get_wp_user_id($user_id_telegram);
        if (!$wp_user_id) {
            $this->send_reply($chat_id, '❌ Пользователь не найден.');
            return false;
        }
        
        // Парсим формат и ID категории (если указан)
        $parts = explode(':', $format_and_category, 2);
        $format = $parts[0];
        $category_id = isset($parts[1]) ? intval($parts[1]) : 0;
        
        // Проверяем PRO статус для PDF и DOCX
        $payment_class = new TCM_Payment();
        $is_pro = $payment_class->check_pro_subscription($wp_user_id);
        
        if (in_array($format, array('pdf', 'docx')) && !$is_pro) {
            // Показываем предложение подключить PRO
            $this->show_pro_required_message($chat_id, $user_id_telegram);
            return false;
        }
        
        // Получаем записи пользователя (все или из указанной категории) - от первой к последней
        $args = array(
            'author' => $wp_user_id,
            'post_type' => 'post',
            'post_status' => array('publish', 'draft'),
            'numberposts' => -1,
            'orderby' => 'date',
            'order' => 'ASC'
        );
        
        if ($category_id > 0) {
            // Если указана категория, получаем записи только из неё
            $args['category__in'] = array($category_id);
        }
        
        $posts = get_posts($args);
        
        if (empty($posts)) {
            $this->send_reply($chat_id, '❌ У вас нет записей для экспорта.');
            return false;
        }
        
        // Генерируем файл в зависимости от формата
        switch ($format) {
            case 'txt':
                return $this->export_to_txt($chat_id, $posts, $user_id_telegram);
            
            case 'pdf':
                return $this->export_to_pdf($chat_id, $posts, $user_id_telegram);
            
            case 'docx':
                return $this->export_to_docx($chat_id, $posts, $user_id_telegram);
            
            case 'pdf_pro':
            case 'docx_pro':
                // Показываем предложение подключить PRO
                $this->show_pro_required_message($chat_id, $user_id_telegram);
                return false;
            
            default:
                $this->send_reply($chat_id, '❌ Неизвестный формат экспорта.');
                return false;
        }
    }
    
    /**
     * Экспорт записей в TXT
     */
    private function export_to_txt($chat_id, $posts, $user_id_telegram) {
        $content = "МОИ ЗАПИСИ\n";
        $content .= "Дата экспорта: " . date('d.m.Y H:i') . "\n";
        $content .= "Всего записей: " . count($posts) . "\n\n";
        $content .= str_repeat("=", 50) . "\n\n";
        
        foreach ($posts as $index => $post) {
            $post_categories = get_the_category($post->ID);
            $post_category_name = !empty($post_categories) ? $post_categories[0]->name : 'Без категории';
            $post_date = date('d.m.Y H:i', strtotime($post->post_date));
            $post_status = $post->post_status === 'publish' ? 'Опубликовано' : 'Черновик';
            
            $content .= ($index + 1) . ". " . $post->post_title . "\n";
            $content .= "Категория: " . $post_category_name . "\n";
            $content .= "Дата: " . $post_date . "\n";
            $content .= "Статус: " . $post_status . "\n\n";
            $content .= strip_tags($post->post_content) . "\n\n";
            $content .= str_repeat("-", 50) . "\n\n";
        }
        
        // Сохраняем во временный файл
        $filename = 'my_posts_' . date('Y-m-d_His') . '.txt';
        $upload_dir = wp_upload_dir();
        $filepath = $upload_dir['basedir'] . '/' . $filename;
        file_put_contents($filepath, $content);
        
        // Отправляем файл
        return $this->send_document($chat_id, $filepath, $filename, 'Ваши записи в формате TXT');
    }
    
    /**
     * Экспорт записей в PDF
     */
    private function export_to_pdf($chat_id, $posts, $user_id_telegram) {
        // Для PDF нужна библиотека, пока отправляем сообщение
        $this->send_reply($chat_id, '⏳ Экспорт в PDF будет доступен в ближайшее время. Пожалуйста, используйте формат TXT.');
        return false;
    }
    
    /**
     * Экспорт записей в DOCX
     */
    private function export_to_docx($chat_id, $posts, $user_id_telegram) {
        // Для DOCX нужна библиотека, пока отправляем сообщение
        $this->send_reply($chat_id, '⏳ Экспорт в DOCX будет доступен в ближайшее время. Пожалуйста, используйте формат TXT.');
        return false;
    }
    
    /**
     * Отправка документа в Telegram
     */
    private function send_document($chat_id, $filepath, $filename, $caption = '') {
        $token = get_option('tcm_telegram_token', '');
        if (empty($token)) {
            return new WP_Error('tcm_no_token', 'Telegram токен не настроен');
        }
        
        $url = "https://api.telegram.org/bot{$token}/sendDocument";
        
        // Используем wp_remote_post с multipart/form-data
        $boundary = wp_generate_password(12, false);
        $delimiter = '-------------' . $boundary;
        
        $post_data = '';
        
        // Добавляем chat_id
        $post_data .= '--' . $delimiter . "\r\n";
        $post_data .= 'Content-Disposition: form-data; name="chat_id"' . "\r\n\r\n";
        $post_data .= $chat_id . "\r\n";
        
        // Добавляем caption если есть
        if (!empty($caption)) {
            $post_data .= '--' . $delimiter . "\r\n";
            $post_data .= 'Content-Disposition: form-data; name="caption"' . "\r\n\r\n";
            $post_data .= $caption . "\r\n";
        }
        
        // Добавляем файл
        $file_content = file_get_contents($filepath);
        $post_data .= '--' . $delimiter . "\r\n";
        $post_data .= 'Content-Disposition: form-data; name="document"; filename="' . $filename . '"' . "\r\n";
        $post_data .= 'Content-Type: text/plain' . "\r\n\r\n";
        $post_data .= $file_content . "\r\n";
        $post_data .= '--' . $delimiter . '--';
        
        $args = array(
            'body' => $post_data,
            'headers' => array(
                'Content-Type' => 'multipart/form-data; boundary=' . $delimiter
            ),
            'timeout' => 60
        );
        
        $response = wp_remote_post($url, $args);
        
        // Удаляем временный файл
        if (file_exists($filepath)) {
            @unlink($filepath);
        }
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($body['ok']) && $body['ok'] === true) {
            return $body;
        }
        
        return new WP_Error('tcm_telegram_error', isset($body['description']) ? $body['description'] : 'Ошибка отправки документа');
    }
    
    /**
     * Обработка кастомного выбора категорий
     */
    private function handle_custom_category($chat_id, $action, $user_id_telegram) {
        $wp_user_id = $this->get_wp_user_id($user_id_telegram);
        if (!$wp_user_id) {
            $this->send_reply($chat_id, '❌ Пользователь не найден.');
            return false;
        }
        
        if ($action === 'menu') {
            return $this->show_custom_category_menu($chat_id, $wp_user_id);
        }
        
        // Если action - это ID категории (Шаг), показываем Главы
        $category_id = intval($action);
        if ($category_id > 0) {
            return $this->show_step_chapters($chat_id, $category_id, $user_id_telegram);
        }
        
        return false;
    }
    
    /**
     * Показ меню выбора категорий с записями - сразу показываем Шаги
     */
    private function show_custom_category_menu($chat_id, $wp_user_id) {
        // Получаем все Шаги (категории уровня 0)
        $all_steps = get_categories(array('parent' => 0, 'hide_empty' => false));
        $steps_with_posts = array();
        
        foreach ($all_steps as $step) {
            // Собираем все дочерние категории (Главы и Точки)
            $category_ids = array($step->term_id);
            $chapters = get_categories(array('parent' => $step->term_id));
            foreach ($chapters as $chapter) {
                $category_ids[] = $chapter->term_id;
                $points = get_categories(array('parent' => $chapter->term_id));
                foreach ($points as $point) {
                    $category_ids[] = $point->term_id;
                }
            }
            
            // Проверяем, есть ли записи в дочерних категориях
            $posts = get_posts(array(
                'author' => $wp_user_id,
                'category__in' => $category_ids,
                'post_type' => 'post',
                'post_status' => array('publish', 'draft'),
                'numberposts' => 1,
                'fields' => 'ids'
            ));
            
            if (!empty($posts)) {
                $steps_with_posts[] = $step;
            }
        }
        
        if (empty($steps_with_posts)) {
            $text = "📝 У вас пока нет записей ни в одном Шаге.";
            $keyboard = array(
                array(
                    array('text' => '🔙 Назад', 'callback_data' => 'view_posts:menu')
                )
            );
            return $this->send_reply_with_keyboard($chat_id, $text, $keyboard);
        }
        
        $keyboard = array();
        foreach ($steps_with_posts as $step) {
            $keyboard[] = array(
                array('text' => esc_html($step->name), 'callback_data' => 'custom_category:' . $step->term_id)
            );
        }
        
        $keyboard[] = array(
            array('text' => '🔙 Назад', 'callback_data' => 'view_posts:menu')
        );
        
        $text = "🔍 <b>Выбор из других рубрик</b>\n\n";
        $text .= "Выберите Шаг:";
        
        return $this->send_reply_with_keyboard($chat_id, $text, $keyboard);
    }
    
    /**
     * Показ списка категорий определенного уровня с записями
     */
    private function show_category_level_list($chat_id, $wp_user_id, $level_name, $level_num) {
        // Получаем все категории этого уровня с записями
        $all_categories = get_categories(array('hide_empty' => false));
        $categories_with_posts = array();
        
        foreach ($all_categories as $category) {
            // Определяем уровень категории
            $level = 0;
            $current = $category;
            while ($current && $current->parent > 0) {
                $level++;
                $current = get_category($current->parent);
                if (!$current) {
                    break;
                }
            }
            
            if ($level == $level_num) {
                // Проверяем, есть ли записи
                $category_ids = array($category->term_id);
                $children = get_categories(array('parent' => $category->term_id));
                foreach ($children as $child) {
                    $category_ids[] = $child->term_id;
                    $grandchildren = get_categories(array('parent' => $child->term_id));
                    foreach ($grandchildren as $grandchild) {
                        $category_ids[] = $grandchild->term_id;
                    }
                }
                
                $posts = get_posts(array(
                    'author' => $wp_user_id,
                    'category__in' => $category_ids,
                    'post_type' => 'post',
                    'post_status' => array('publish', 'draft'),
                    'numberposts' => 1,
                    'fields' => 'ids'
                ));
                
                if (!empty($posts)) {
                    $categories_with_posts[] = $category;
                }
            }
        }
        
        if (empty($categories_with_posts)) {
            $text = "📝 У вас нет записей в категориях уровня <b>{$level_name}</b>.";
            $keyboard = array(
                array(
                    array('text' => '🔙 Назад', 'callback_data' => 'custom_category:menu')
                )
            );
            return $this->send_reply_with_keyboard($chat_id, $text, $keyboard);
        }
        
        $keyboard = array();
        foreach ($categories_with_posts as $category) {
            $keyboard[] = array(
                array('text' => esc_html($category->name), 'callback_data' => 'custom_category:' . $category->term_id)
            );
        }
        
        $keyboard[] = array(
            array('text' => '🔙 Назад', 'callback_data' => 'custom_category:menu')
        );
        
        $text = "🔍 <b>Выбор {$level_name}</b>\n\n";
        $text .= "Выберите категорию:";
        
        return $this->send_reply_with_keyboard($chat_id, $text, $keyboard);
    }
    
    /**
     * Показ Глав выбранного Шага
     */
    private function show_step_chapters($chat_id, $step_id, $user_id_telegram) {
        $wp_user_id = $this->get_wp_user_id($user_id_telegram);
        if (!$wp_user_id) {
            $this->send_reply($chat_id, '❌ Пользователь не найден.');
            return false;
        }
        
        $step = get_category($step_id);
        if (!$step || $step->parent != 0) {
            $this->send_reply($chat_id, '❌ Шаг не найден.');
            return false;
        }
        
        // Получаем все Главы этого Шага
        $chapters = get_categories(array('parent' => $step_id, 'hide_empty' => false));
        $chapters_with_posts = array();
        
        foreach ($chapters as $chapter) {
            // Собираем все дочерние Точки
            $category_ids = array($chapter->term_id);
            $points = get_categories(array('parent' => $chapter->term_id));
            foreach ($points as $point) {
                $category_ids[] = $point->term_id;
            }
            
            // Проверяем, есть ли записи
            $posts = get_posts(array(
                'author' => $wp_user_id,
                'category__in' => $category_ids,
                'post_type' => 'post',
                'post_status' => array('publish', 'draft'),
                'numberposts' => 1,
                'fields' => 'ids'
            ));
            
            if (!empty($posts)) {
                $chapters_with_posts[] = $chapter;
            }
        }
        
        if (empty($chapters_with_posts)) {
            $text = "📝 В Шаге <b>" . esc_html($step->name) . "</b> нет записей.";
            $keyboard = array(
                array(
                    array('text' => '🔙 Назад', 'callback_data' => 'custom_category:menu')
                )
            );
            return $this->send_reply_with_keyboard($chat_id, $text, $keyboard);
        }
        
        $keyboard = array();
        foreach ($chapters_with_posts as $chapter) {
            // Получаем количество записей пользователя в этой Главе
            $posts_count = $this->get_category_posts_count($chapter->term_id, $wp_user_id);
            $chapter_name = esc_html($chapter->name);
            if ($posts_count > 0) {
                $chapter_name .= ' (' . $posts_count . ')';
            }
            $keyboard[] = array(
                array('text' => $chapter_name, 'callback_data' => 'custom_category:chapter_view:' . $chapter->term_id)
            );
        }
        
        $keyboard[] = array(
            array('text' => '🔙 Назад', 'callback_data' => 'custom_category:menu')
        );
        
        $text = "📚 <b>Шаг: " . esc_html($step->name) . "</b>\n\n";
        $text .= "Выберите Главу:";
        
        return $this->send_reply_with_keyboard($chat_id, $text, $keyboard);
    }
    
    /**
     * Показ Точек выбранной Главы
     */
    private function show_chapter_points($chat_id, $chapter_id, $user_id_telegram) {
        $wp_user_id = $this->get_wp_user_id($user_id_telegram);
        if (!$wp_user_id) {
            $this->send_reply($chat_id, '❌ Пользователь не найден.');
            return false;
        }
        
        $chapter = get_category($chapter_id);
        if (!$chapter) {
            $this->send_reply($chat_id, '❌ Глава не найдена.');
            return false;
        }
        
        // Получаем родительский Шаг для навигации назад
        $step = get_category($chapter->parent);
        
        // Получаем все Точки этой Главы
        $points = get_categories(array('parent' => $chapter_id, 'hide_empty' => false));
        $points_with_posts = array();
        
        foreach ($points as $point) {
            // Проверяем, есть ли записи в этой Точке
            $posts = get_posts(array(
                'author' => $wp_user_id,
                'category__in' => array($point->term_id),
                'post_type' => 'post',
                'post_status' => array('publish', 'draft'),
                'numberposts' => 1,
                'fields' => 'ids'
            ));
            
            if (!empty($posts)) {
                $points_with_posts[] = $point;
            }
        }
        
        if (empty($points_with_posts)) {
            $text = "📝 В Главе <b>" . esc_html($chapter->name) . "</b> нет записей.";
            $keyboard = array(
                array(
                    array('text' => '🔙 Назад', 'callback_data' => 'custom_category:step_view:' . ($step ? $step->term_id : 0))
                )
            );
            return $this->send_reply_with_keyboard($chat_id, $text, $keyboard);
        }
        
        $keyboard = array();
        foreach ($points_with_posts as $point) {
            // Получаем количество записей пользователя в этой Точке
            $posts_count = $this->get_category_posts_count($point->term_id, $wp_user_id);
            $point_name = esc_html($point->name);
            if ($posts_count > 0) {
                $point_name .= ' (' . $posts_count . ')';
            }
            $keyboard[] = array(
                array('text' => $point_name, 'callback_data' => 'custom_category:point_view:' . $point->term_id)
            );
        }
        
        $keyboard[] = array(
            array('text' => '🔙 Назад', 'callback_data' => 'custom_category:step_view:' . ($step ? $step->term_id : 0))
        );
        
        $posts_count = $this->get_category_posts_count($chapter_id, $wp_user_id);
        $chapter_name = esc_html($chapter->name);
        if ($posts_count > 0) {
            $chapter_name .= ' (' . $posts_count . ')';
        }
        $text = "📖 <b>Глава: " . $chapter_name . "</b>\n\n";
        $text .= "Выберите Точку:";
        
        return $this->send_reply_with_keyboard($chat_id, $text, $keyboard);
    }
    
    /**
     * Показ записей Точки, сгруппированных по Главам
     */
    private function show_point_posts_grouped($chat_id, $point_id, $user_id_telegram) {
        $wp_user_id = $this->get_wp_user_id($user_id_telegram);
        if (!$wp_user_id) {
            $this->send_reply($chat_id, '❌ Пользователь не найден.');
            return false;
        }
        
        $point = get_category($point_id);
        if (!$point) {
            $this->send_reply($chat_id, '❌ Точка не найдена.');
            return false;
        }
        
        // Получаем родительскую Главу
        $chapter = get_category($point->parent);
        $step = $chapter ? get_category($chapter->parent) : null;
        
        // Получаем все записи в этой Точке (от первой к последней)
        $posts = get_posts(array(
            'author' => $wp_user_id,
            'category__in' => array($point_id),
            'post_type' => 'post',
            'post_status' => array('publish', 'draft'),
            'numberposts' => -1,
            'orderby' => 'date',
            'order' => 'ASC'
        ));
        
        if (empty($posts)) {
            $text = "📝 В Точке <b>" . esc_html($point->name) . "</b> нет записей.";
            $keyboard = array(
                array(
                    array('text' => '🔙 Назад', 'callback_data' => 'custom_category:chapter_view:' . ($chapter ? $chapter->term_id : 0))
                )
            );
            return $this->send_reply_with_keyboard($chat_id, $text, $keyboard);
        }
        
        // Группируем записи по Главам (все записи в одной Точке, но показываем структуру)
        $text = "📝 <b>Записи: " . esc_html($point->name) . "</b>\n\n";
        
        if ($chapter) {
            $text .= "📖 <b>Глава: " . esc_html($chapter->name) . "</b>\n\n";
        }
        
        $text .= "Всего записей: " . count($posts) . "\n\n";
        $text .= "━━━━━━━━━━━━━━━━━━━━\n\n";
        
        foreach ($posts as $index => $post) {
            $post_date = date('d.m.Y H:i', strtotime($post->post_date));
            $post_status = $post->post_status === 'publish' ? 'Опубликовано' : 'Черновик';
            
            $text .= "<b>" . ($index + 1) . ". " . esc_html($post->post_title) . "</b>\n";
            $text .= "📅 Дата: {$post_date}\n";
            $text .= "📊 Статус: {$post_status}\n\n";
            $text .= esc_html($post->post_content) . "\n\n";
            $text .= "━━━━━━━━━━━━━━━━━━━━\n\n";
        }
        
        // Проверяем PRO статус
        $payment_class = new TCM_Payment();
        $is_pro = $payment_class->check_pro_subscription($wp_user_id);
        
        // Формируем клавиатуру с кнопками экспорта
        $keyboard = array();
        
        // Добавляем кнопки редактирования для каждой записи (по 2 в ряд)
        $edit_buttons = array();
        foreach ($posts as $index => $post) {
            $button_text = '✏️ ' . ($index + 1);
            $edit_buttons[] = array('text' => $button_text, 'callback_data' => 'edit_post:' . $post->ID);
            
            // Добавляем ряд кнопок каждые 2 записи
            if (count($edit_buttons) >= 2 || $index === count($posts) - 1) {
                $keyboard[] = $edit_buttons;
                $edit_buttons = array();
            }
        }
        
        // Кнопка экспорта в TXT (доступна всем)
        $keyboard[] = array(
            array('text' => '💾 Сохранить в TXT', 'callback_data' => 'export_posts:txt:' . $point_id)
        );
        
        // Кнопки экспорта в PDF и DOCX (только для PRO)
        if ($is_pro) {
            $keyboard[] = array(
                array('text' => '📄 Сохранить в PDF', 'callback_data' => 'export_posts:pdf:' . $point_id),
                array('text' => '📝 Сохранить в DOCX', 'callback_data' => 'export_posts:docx:' . $point_id)
            );
        } else {
            $keyboard[] = array(
                array('text' => '⭐ PRO 📄 Сохранить в PDF', 'callback_data' => 'export_posts:pdf_pro'),
                array('text' => '⭐ PRO 📝 Сохранить в DOCX', 'callback_data' => 'export_posts:docx_pro')
            );
        }
        
        // Кнопка "Назад"
        $keyboard[] = array(
            array('text' => '🔙 Назад', 'callback_data' => 'custom_category:chapter_view:' . ($chapter ? $chapter->term_id : 0))
        );
        
        // Если текст слишком длинный, разбиваем на несколько сообщений
        $max_length = 4000;
        if (strlen($text) > $max_length) {
            $first_part = substr($text, 0, $max_length);
            $last_newline = strrpos($first_part, "\n\n");
            if ($last_newline !== false) {
                $first_part = substr($text, 0, $last_newline);
            }
            $this->send_reply($chat_id, $first_part);
            
            $remaining = substr($text, strlen($first_part));
            while (strlen($remaining) > $max_length) {
                $part = substr($remaining, 0, $max_length);
                $last_newline = strrpos($part, "\n\n");
                if ($last_newline !== false) {
                    $part = substr($remaining, 0, $last_newline);
                }
                $this->send_reply($chat_id, $part);
                $remaining = substr($remaining, strlen($part));
            }
            
            if (!empty($remaining)) {
                return $this->send_reply_with_keyboard($chat_id, $remaining, $keyboard);
            } else {
                return $this->send_reply_with_keyboard($chat_id, "💾 <b>Экспорт записей</b>\n\nВыберите формат для сохранения:", $keyboard);
            }
        }
        
        return $this->send_reply_with_keyboard($chat_id, $text, $keyboard);
    }
    
    /**
     * Получение списка активных кодов верификации
     * 
     * @return array Массив с информацией о кодах (code, created, user_id)
     */
    private function get_active_verification_codes() {
        $active_codes = array();
        $code_expires_in = 24 * 3600; // 24 часа в секундах
        $current_time = current_time('timestamp');
        
        // Получаем всех пользователей с кодами верификации
        $users_with_codes = get_users(array(
            'meta_key' => 'tcm_verification_code',
            'meta_compare' => 'EXISTS'
        ));
        
        foreach ($users_with_codes as $user) {
            $code = get_user_meta($user->ID, 'tcm_verification_code', true);
            $code_created = get_user_meta($user->ID, 'tcm_verification_code_created', true);
            
            if (empty($code)) {
                continue;
            }
            
            // Если время создания не сохранено, считаем код активным (для совместимости со старыми кодами)
            if (empty($code_created)) {
                $active_codes[] = array(
                    'code' => $code,
                    'created' => 0,
                    'user_id' => $user->ID,
                    'user_name' => $user->display_name
                );
                continue;
            }
            
            // Проверяем, не истек ли код
            $time_passed = $current_time - $code_created;
            if ($time_passed <= $code_expires_in) {
                $active_codes[] = array(
                    'code' => $code,
                    'created' => $code_created,
                    'user_id' => $user->ID,
                    'user_name' => $user->display_name
                );
            } else {
                // Код истек - удаляем его
                delete_user_meta($user->ID, 'tcm_verification_code');
                delete_user_meta($user->ID, 'tcm_verification_code_created');
            }
        }
        
        // Сортируем по времени создания (самые свежие первыми)
        usort($active_codes, function($a, $b) {
            return $b['created'] - $a['created'];
        });
        
        return $active_codes;
    }
    
    /**
     * Показ инструкции для регистрации
     */
    private function show_registration_instruction($chat_id) {
        $message = 
            "👋 <b>Добро пожаловать!</b>\n\n" .
            "Для начала работы необходимо зарегистрироваться.\n\n" .
            "📝 <b>Как зарегистрироваться:</b>\n\n" .
            "1️⃣ Отправьте команду: /register\n\n" .
            "2️⃣ Бот запросит ваше имя - отправьте его (например: Иван Иванов)\n\n" .
            "3️⃣ После регистрации вы сможете:\n" .
            "• Выбирать категории для записей\n" .
            "• Создавать записи, отправляя сообщения боту\n" .
            "• Просматривать свои записи\n\n" .
            "💡 <b>Начните с команды:</b> /register";
        
        $this->send_reply_with_reply_keyboard($chat_id, $message, $this->get_main_reply_keyboard());
    }
    
    /**
     * Показ настроек напоминания
     */
    private function show_reminder_settings($chat_id, $user_id_telegram) {
        $wp_user_id = $this->get_wp_user_id($user_id_telegram);
        if (!$wp_user_id) {
            $this->send_reply($chat_id, '❌ Пользователь не найден. Пожалуйста, зарегистрируйтесь через /register');
            return false;
        }
        
        $reminder_time = get_user_meta($wp_user_id, 'tcm_daily_reminder_time', true);
        $timezone_offset = get_user_meta($wp_user_id, 'tcm_timezone_offset', true);
        
        // Если часовой пояс не установлен, используем 0 (UTC)
        if ($timezone_offset === '') {
            $timezone_offset = 0;
        } else {
            $timezone_offset = intval($timezone_offset);
        }
        
        // Получаем время сервера
        $server_time = current_time('H:i');
        $server_date = current_time('d.m.Y');
        
        // Вычисляем время пользователя
        $user_time = $this->get_user_local_time($timezone_offset);
        $user_date = $this->get_user_local_date($timezone_offset);
        
        $text = "⏰ <b>Настройка ежедневного напоминания</b>\n\n";
        
        // Показываем время сервера и пользователя
        $text .= "🖥️ <b>Время сервера:</b> " . esc_html($server_time) . " (" . esc_html($server_date) . ")\n";
        $timezone_str = $timezone_offset >= 0 ? '+' . $timezone_offset : (string)$timezone_offset;
        $text .= "👤 <b>Ваше время:</b> " . esc_html($user_time) . " (" . esc_html($user_date) . ") UTC" . $timezone_str . "\n\n";
        
        if ($reminder_time) {
            $text .= "✅ <b>Время напоминания:</b> " . esc_html($reminder_time) . " (ваше местное время)\n\n";
        } else {
            $text .= "❌ <b>Напоминание не настроено</b>\n\n";
        }
        
        $text .= "Выберите время для ежедневного напоминания:\n\n";
        $text .= "💡 <b>Напоминание будет приходить каждый день в указанное время, если вы еще не написали шаг сегодня.</b>";
        
        // Создаем кнопки с временем (каждый час от 6 до 23)
        $keyboard = array();
        $row = array();
        $hour = 6;
        
        while ($hour <= 23) {
            $time_str = sprintf('%02d:00', $hour);
            $row[] = array('text' => $time_str, 'callback_data' => 'set_reminder_time:' . $time_str);
            
            if (count($row) == 3) {
                $keyboard[] = $row;
                $row = array();
            }
            
            $hour++;
        }
        
        if (!empty($row)) {
            $keyboard[] = $row;
        }
        
        if ($reminder_time) {
            $keyboard[] = array(
                array('text' => '❌ Отключить напоминание', 'callback_data' => 'disable_reminder')
            );
        }
        
        $keyboard[] = array(
            array('text' => '🌍 Настроить часовой пояс', 'callback_data' => 'timezone_settings')
        );
        
        $keyboard[] = array(
            array('text' => '🔙 Назад к настройкам', 'callback_data' => 'settings')
        );
        
        return $this->send_reply_with_keyboard($chat_id, $text, $keyboard);
    }
    
    /**
     * Обработка установки времени напоминания
     */
    private function handle_set_reminder_time($chat_id, $user_id_telegram, $time) {
        $wp_user_id = $this->get_wp_user_id($user_id_telegram);
        if (!$wp_user_id) {
            $this->send_reply($chat_id, '❌ Пользователь не найден. Пожалуйста, зарегистрируйтесь через /register');
            return false;
        }
        
        // Проверяем формат времени (HH:MM)
        if (!preg_match('/^([0-1][0-9]|2[0-3]):00$/', $time)) {
            $this->send_reply($chat_id, '❌ Неверный формат времени. Используйте формат HH:00 (например, 09:00)');
            return false;
        }
        
        // Сохраняем время напоминания
        update_user_meta($wp_user_id, 'tcm_daily_reminder_time', $time);
        
        // Получаем Telegram chat_id пользователя
        $telegram_chat_id = get_user_meta($wp_user_id, 'tcm_telegram_chat_id', true);
        if (!$telegram_chat_id) {
            $telegram_chat_id = $chat_id;
        }
        
        $text = "✅ <b>Напоминание настроено!</b>\n\n";
        $text .= "⏰ <b>Время:</b> " . esc_html($time) . "\n\n";
        $text .= "Каждый день в это время вы будете получать напоминание \"Напишем шаг?\", если еще не написали сегодня.";
        
        $keyboard = array(
            array(
                array('text' => '🔙 Назад к настройкам', 'callback_data' => 'settings')
            )
        );
        
        return $this->send_reply_with_keyboard($chat_id, $text, $keyboard);
    }
    
    /**
     * Обработка отключения напоминания
     */
    private function handle_disable_reminder($chat_id, $user_id_telegram) {
        $wp_user_id = $this->get_wp_user_id($user_id_telegram);
        if (!$wp_user_id) {
            $this->send_reply($chat_id, '❌ Пользователь не найден. Пожалуйста, зарегистрируйтесь через /register');
            return false;
        }
        
        // Удаляем время напоминания
        delete_user_meta($wp_user_id, 'tcm_daily_reminder_time');
        
        $text = "✅ <b>Напоминание отключено</b>\n\n";
        $text .= "Вы больше не будете получать ежедневные напоминания.";
        
        $keyboard = array(
            array(
                array('text' => '🔙 Назад к настройкам', 'callback_data' => 'settings')
            )
        );
        
        return $this->send_reply_with_keyboard($chat_id, $text, $keyboard);
    }
    
    /**
     * Отображение настроек часового пояса
     */
    private function show_timezone_settings($chat_id, $user_id_telegram) {
        $wp_user_id = $this->get_wp_user_id($user_id_telegram);
        if (!$wp_user_id) {
            $this->send_reply($chat_id, '❌ Пользователь не найден. Пожалуйста, зарегистрируйтесь через /register');
            return false;
        }
        
        $timezone_offset = get_user_meta($wp_user_id, 'tcm_timezone_offset', true);
        if ($timezone_offset === '') {
            $timezone_offset = 0;
        } else {
            $timezone_offset = intval($timezone_offset);
        }
        
        // Получаем время сервера и пользователя
        $server_time = current_time('H:i');
        $server_date = current_time('d.m.Y');
        $user_time = $this->get_user_local_time($timezone_offset);
        $user_date = $this->get_user_local_date($timezone_offset);
        
        $text = "🌍 <b>Настройка часового пояса</b>\n\n";
        $text .= "🖥️ <b>Время сервера:</b> " . esc_html($server_time) . " (" . esc_html($server_date) . ")\n";
        $timezone_str = $timezone_offset >= 0 ? '+' . $timezone_offset : (string)$timezone_offset;
        $text .= "👤 <b>Ваше время:</b> " . esc_html($user_time) . " (" . esc_html($user_date) . ") UTC" . $timezone_str . "\n\n";
        $text .= "Выберите ваш часовой пояс (смещение от UTC):\n\n";
        $text .= "💡 <b>Время напоминания будет рассчитываться по вашему местному времени.</b>";
        
        // Создаем кнопки с часовыми поясами (от UTC-12 до UTC+14)
        $keyboard = array();
        $row = array();
        $offset = -12;
        
        while ($offset <= 14) {
            $offset_str = $offset >= 0 ? '+' . $offset : (string)$offset;
            $text_btn = 'UTC' . $offset_str;
            if ($timezone_offset == $offset) {
                $text_btn = '✅ ' . $text_btn;
            }
            $row[] = array('text' => $text_btn, 'callback_data' => 'set_timezone:' . $offset);
            
            if (count($row) == 3) {
                $keyboard[] = $row;
                $row = array();
            }
            
            $offset++;
        }
        
        if (!empty($row)) {
            $keyboard[] = $row;
        }
        
        $keyboard[] = array(
            array('text' => '🔙 Назад к настройкам напоминания', 'callback_data' => 'reminder_settings')
        );
        
        return $this->send_reply_with_keyboard($chat_id, $text, $keyboard);
    }
    
    /**
     * Обработка установки часового пояса
     */
    private function handle_set_timezone($chat_id, $user_id_telegram, $offset) {
        $wp_user_id = $this->get_wp_user_id($user_id_telegram);
        if (!$wp_user_id) {
            $this->send_reply($chat_id, '❌ Пользователь не найден. Пожалуйста, зарегистрируйтесь через /register');
            return false;
        }
        
        // Проверяем, что offset в допустимом диапазоне
        $offset = intval($offset);
        if ($offset < -12 || $offset > 14) {
            $this->send_reply($chat_id, '❌ Неверное значение часового пояса. Используйте значение от -12 до +14');
            return false;
        }
        
        // Сохраняем часовой пояс
        update_user_meta($wp_user_id, 'tcm_timezone_offset', $offset);
        
        // Получаем обновленное время пользователя
        $user_time = $this->get_user_local_time($offset);
        $user_date = $this->get_user_local_date($offset);
        $server_time = current_time('H:i');
        $server_date = current_time('d.m.Y');
        
        $timezone_str = $offset >= 0 ? '+' . $offset : (string)$offset;
        
        $text = "✅ <b>Часовой пояс настроен!</b>\n\n";
        $text .= "🖥️ <b>Время сервера:</b> " . esc_html($server_time) . " (" . esc_html($server_date) . ")\n";
        $text .= "👤 <b>Ваше время:</b> " . esc_html($user_time) . " (" . esc_html($user_date) . ") UTC" . $timezone_str . "\n\n";
        $text .= "Теперь время напоминания будет рассчитываться по вашему местному времени.";
        
        $keyboard = array(
            array(
                array('text' => '🔙 Назад к настройкам напоминания', 'callback_data' => 'reminder_settings')
            )
        );
        
        return $this->send_reply_with_keyboard($chat_id, $text, $keyboard);
    }
    
    /**
     * Получение локального времени пользователя
     */
    private function get_user_local_time($timezone_offset) {
        $server_timestamp = current_time('timestamp');
        $user_timestamp = $server_timestamp + ($timezone_offset * 3600);
        return date('H:i', $user_timestamp);
    }
    
    /**
     * Получение локальной даты пользователя
     */
    private function get_user_local_date($timezone_offset) {
        $server_timestamp = current_time('timestamp');
        $user_timestamp = $server_timestamp + ($timezone_offset * 3600);
        return date('d.m.Y', $user_timestamp);
    }
    
    /**
     * Проверка, писал ли пользователь сегодня
     */
    private function user_wrote_today($wp_user_id) {
        if (!$wp_user_id) {
            return false;
        }
        
        // Получаем начало и конец сегодняшнего дня
        $today_start = strtotime('today midnight');
        $today_end = strtotime('tomorrow midnight') - 1;
        
        // Проверяем, есть ли посты пользователя за сегодня
        $posts = get_posts(array(
            'author' => $wp_user_id,
            'post_type' => 'post',
            'post_status' => array('publish', 'draft', 'pending'),
            'date_query' => array(
                array(
                    'after' => date('Y-m-d H:i:s', $today_start),
                    'before' => date('Y-m-d H:i:s', $today_end),
                    'inclusive' => true
                )
            ),
            'fields' => 'ids',
            'posts_per_page' => 1
        ));
        
        return !empty($posts);
    }
    
    /**
     * Отправка ежедневных напоминаний (вызывается через cron)
     */
    public function send_daily_reminders() {
        $log_enabled = defined('WP_DEBUG') && WP_DEBUG;
        
        if ($log_enabled) {
            error_log('TCM: send_daily_reminders called');
        }
        
        // Получаем всех пользователей с настроенным временем напоминания
        $users = get_users(array(
            'meta_key' => 'tcm_daily_reminder_time',
            'meta_compare' => 'EXISTS'
        ));
        
        if ($log_enabled) {
            error_log('TCM: Found ' . count($users) . ' users with reminder time');
        }
        
        foreach ($users as $user) {
            $reminder_time = get_user_meta($user->ID, 'tcm_daily_reminder_time', true);
            
            if (!$reminder_time) {
                continue;
            }
            
            // Получаем часовой пояс пользователя
            $timezone_offset = get_user_meta($user->ID, 'tcm_timezone_offset', true);
            if ($timezone_offset === '') {
                $timezone_offset = 0;
            } else {
                $timezone_offset = intval($timezone_offset);
            }
            
            // Получаем текущее время пользователя (с учетом часового пояса)
            $user_current_time = $this->get_user_local_time($timezone_offset);
            
            // Проверяем, совпадает ли текущее время пользователя с временем напоминания
            if ($user_current_time !== $reminder_time) {
                if ($log_enabled) {
                    error_log('TCM: User ' . $user->ID . ' - Server time: ' . current_time('H:i') . ', User time: ' . $user_current_time . ', Reminder time: ' . $reminder_time);
                }
                continue;
            }
            
            // Проверяем, писал ли пользователь сегодня
            if ($this->user_wrote_today($user->ID)) {
                if ($log_enabled) {
                    error_log('TCM: User ' . $user->ID . ' already wrote today, skipping reminder');
                }
                continue;
            }
            
            // Получаем Telegram chat_id пользователя
            $telegram_chat_id = get_user_meta($user->ID, 'tcm_telegram_chat_id', true);
            $telegram_id = get_user_meta($user->ID, 'tcm_telegram_id', true);
            
            if (!$telegram_chat_id && !$telegram_id) {
                if ($log_enabled) {
                    error_log('TCM: User ' . $user->ID . ' has no Telegram chat ID');
                }
                continue;
            }
            
            // Используем chat_id, если есть, иначе telegram_id
            $chat_id = $telegram_chat_id ? $telegram_chat_id : $telegram_id;
            
            // Отправляем напоминание
            $text = "⏰ <b>Напоминание</b>\n\n";
            $text .= "Напишем шаг?";
            
            $keyboard = array(
                array(
                    array('text' => '📂 Выбрать категорию', 'callback_data' => 'category:0'),
                    array('text' => '🏠 Главное меню', 'callback_data' => 'menu')
                )
            );
            
            $result = $this->send_reply_with_keyboard($chat_id, $text, $keyboard);
            
            if ($log_enabled) {
                if (is_wp_error($result)) {
                    error_log('TCM: Error sending reminder to user ' . $user->ID . ': ' . $result->get_error_message());
                } else {
                    error_log('TCM: Reminder sent to user ' . $user->ID);
                }
            }
        }
    }
}
