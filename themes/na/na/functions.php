<?php
	/**
		* na functions and definitions
		*
		* @link https://developer.wordpress.org/themes/basics/theme-functions/
		*
		* @package na
	*/
	
	if ( ! defined( '_S_VERSION' ) ) {
		// Replace the version number of the theme on each release.
		define( '_S_VERSION', '1.0.0' );
	}
	
	/**
		* Sets up theme defaults and registers support for various WordPress features.
		*
		* Note that this function is hooked into the after_setup_theme hook, which
		* runs before the init hook. The init hook is too late for some features, such
		* as indicating support for post thumbnails.
	*/
	function na_setup() {
		/*
			* Make theme available for translation.
			* Translations can be filed in the /languages/ directory.
			* If you're building a theme based on na, use a find and replace
			* to change 'na' to the name of your theme in all the template files.
		*/
		load_theme_textdomain( 'na', get_template_directory() . '/languages' );
		
		// Add default posts and comments RSS feed links to head.
		add_theme_support( 'automatic-feed-links' );
		
		/*
			* Let WordPress manage the document title.
			* By adding theme support, we declare that this theme does not use a
			* hard-coded <title> tag in the document head, and expect WordPress to
			* provide it for us.
		*/
		add_theme_support( 'title-tag' );
		
		/*
			* Enable support for Post Thumbnails on posts and pages.
			*
			* @link https://developer.wordpress.org/themes/functionality/featured-images-post-thumbnails/
		*/
		add_theme_support( 'post-thumbnails' );
		
		// This theme uses wp_nav_menu() in one location.
		register_nav_menus(
		array(
		'menu-1' => esc_html__( 'Primary', 'na' ),
		)
		);
		
		/*
			* Switch default core markup for search form, comment form, and comments
			* to output valid HTML5.
		*/
		add_theme_support(
		'html5',
		array(
		'search-form',
		'comment-form',
		'comment-list',
		'gallery',
		'caption',
		'style',
		'script',
		)
		);
		
		// Set up the WordPress core custom background feature.
		add_theme_support(
		'custom-background',
		apply_filters(
		'na_custom_background_args',
		array(
		'default-color' => 'ffffff',
		'default-image' => '',
		)
		)
		);
		
		// Add theme support for selective refresh for widgets.
		add_theme_support( 'customize-selective-refresh-widgets' );
		
		/**
			* Add support for core custom logo.
			*
			* @link https://codex.wordpress.org/Theme_Logo
		*/
		add_theme_support(
		'custom-logo',
		array(
		'height'      => 250,
		'width'       => 250,
		'flex-width'  => true,
		'flex-height' => true,
		)
		);
	}
	add_action( 'after_setup_theme', 'na_setup' );
	
	/**
		* Set the content width in pixels, based on the theme's design and stylesheet.
		*
		* Priority 0 to make it available to lower priority callbacks.
		*
		* @global int $content_width
	*/
	function na_content_width() {
		$GLOBALS['content_width'] = apply_filters( 'na_content_width', 640 );
	}
	add_action( 'after_setup_theme', 'na_content_width', 0 );
	
	/**
		* Register widget area.
		*
		* @link https://developer.wordpress.org/themes/functionality/sidebars/#registering-a-sidebar
	*/
	function na_widgets_init() {
		register_sidebar(
		array(
		'name'          => esc_html__( 'Sidebar', 'na' ),
		'id'            => 'sidebar-1',
		'description'   => esc_html__( 'Add widgets here.', 'na' ),
		'before_widget' => '<section id="%1$s" class="widget %2$s">',
		'after_widget'  => '</section>',
		'before_title'  => '<h2 class="widget-title">',
		'after_title'   => '</h2>',
		)
		);
	}
	add_action( 'widgets_init', 'na_widgets_init' );
	
	/**
		* Enqueue scripts and styles.
	*/
	function na_scripts() {
		wp_enqueue_style( 'na-style', get_stylesheet_uri(), array(), _S_VERSION );
		wp_style_add_data( 'na-style', 'rtl', 'replace' );
		
		wp_enqueue_style( 'font-awesome', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css' );
		
		
		wp_enqueue_script( 'na-navigation', get_template_directory_uri() . '/js/navigation.js', array(), _S_VERSION, true );
		
		wp_enqueue_script( 'na-main', get_template_directory_uri() . '/js/main.js', array(), _S_VERSION, true );
		
		$script_data = array(
        'editPostUrl' => esc_url(admin_url('admin-post.php?action=edit_post')),
        'ajaxUrl' => admin_url('admin-ajax.php')
		);
		
		wp_localize_script('na-main', 'telegram_form_ajax', array(
        'ajax_url' => admin_url('admin-ajax.php')
		));
		
		wp_localize_script('na-main', 'MyScriptData', $script_data);
		
		if ( is_singular() && comments_open() && get_option( 'thread_comments' ) ) {
			wp_enqueue_script( 'comment-reply' );
		}
	}
	add_action( 'wp_enqueue_scripts', 'na_scripts' );
	
	/**
		* Implement the Custom Header feature.
	*/
	require get_template_directory() . '/inc/custom-header.php';
	
	/**
		* Custom template tags for this theme.
	*/
	require get_template_directory() . '/inc/template-tags.php';
	
	/**
		* Functions which enhance the theme by hooking into WordPress.
	*/
	require get_template_directory() . '/inc/template-functions.php';
	
	/**
		* Customizer additions.
	*/
	require get_template_directory() . '/inc/customizer.php';
	
	
	require get_template_directory() . '/include/acf.php';
	
	/**
		* Load Jetpack compatibility file.
	*/
	if ( defined( 'JETPACK__VERSION' ) ) {
		require get_template_directory() . '/inc/jetpack.php';
	}
	
	
	function handle_create_new_post() {
		if ( isset($_POST['post_content'], $_POST['category_id']) ) {
			$current_user = wp_get_current_user();
			$category_id = intval( $_POST['category_id'] );
			$post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
			
			if ( $post_id > 0 ) {
				// Редактирование существующей записи
				$existing_post = get_post( $post_id );
				if ( $existing_post && $existing_post->post_author == $current_user->ID ) {
					$updated_post = array(
                    'ID'           => $post_id,
                    'post_content' => wp_kses_post( $_POST['post_content'] ),
                    'post_status'  => isset( $_POST['publish_post'] ) && $_POST['publish_post'] == '1' ? 'publish' : 'publish',
					);
					
					wp_update_post( $updated_post );
					wp_redirect( get_category_link( $category_id ) );
					exit;
					} else {
					wp_die('You are not allowed to edit this post.');
				}
				} else {
				// Создание новой записи
				$user_posts = get_posts( array(
                'author' => $current_user->ID,
                'category' => $category_id,
                'post_type' => 'post',
                'post_status' => array('publish', 'draft'),
                'fields' => 'ids',
				) );
				
				$post_count = count( $user_posts ) + 1;
				
				$category_name = get_cat_name( $category_id );
				$post_title = $category_name . ' - ' . $current_user->user_login . ' - ' . $post_count;
				
				$new_post = array(
                'post_title'   => sanitize_text_field( $post_title ),
                'post_content' => wp_kses_post( $_POST['post_content'] ),
                'post_status'  => isset( $_POST['publish_post'] ) && $_POST['publish_post'] == '1' ? 'publish' : 'publish',
                'post_category'=> array( $category_id ),
                'post_author'  => $current_user->ID,
				);
				
				$post_id = wp_insert_post( $new_post );
				
				if ( $post_id ) {
					wp_redirect( get_category_link( $category_id ) );
					exit;
					} else {
					wp_die('Error creating post.');
				}
			}
		}
	}
	add_action( 'admin_post_create_new_post', 'handle_create_new_post' );
	add_action( 'admin_post_nopriv_create_new_post', 'handle_create_new_post' );
	add_action( 'admin_post_edit_post', 'handle_create_new_post' );
	add_action( 'admin_post_nopriv_edit_post', 'handle_create_new_post' );
	
	// Функция для получения самого первого предка (корневой категории)
	function get_root_category($cat_id) {
		$category = get_category($cat_id);
		while ($category->parent != 0) {
			$category = get_category($category->parent);
		}
		return $category;
	}
	
	
	// Функция для вывода списка родительских рубрик с последней записью текущего пользователя
	function display_parent_categories_with_latest_post_shortcode() {
		$excluded_category_id = 1; // Замените на ID категории, которую нужно исключить
		$current_user_id = get_current_user_id();
		
		// Инициализация переменных
		$root_category_id = 0;
		$latest_post_date = '';
		$latest_post_url = '';
		
		if($current_user_id != 0){
			// Получение последней записи текущего пользователя любого статуса
			$latest_post_args = array(
			'author'         => $current_user_id,
			'posts_per_page' => 1,
			'post_status'    => array('publish', 'draft', 'pending', 'private', 'future'),
			'orderby'        => 'date',
			'order'          => 'DESC',
			);
			$latest_post_query = new WP_Query($latest_post_args);
			$latest_post = null;
			
			if ($latest_post_query->have_posts()) {
				while ($latest_post_query->have_posts()) {
					$latest_post_query->the_post();
					$latest_post = get_post();
				}
				wp_reset_postdata();
			}
			
			// Получение корневой категории последней записи
			$root_category_id = 0;
			$latest_post_date = '';
			$latest_post_url = '';
			
			if ($latest_post) {
				$latest_post_categories = get_the_category($latest_post->ID);
				if (!empty($latest_post_categories)) {
					$root_category = get_root_category($latest_post_categories[0]->term_id);
					$root_category_id = $root_category->term_id;
					$latest_post_date = get_the_date('d.m.Y H:i', $latest_post->ID);
					$latest_post_url = get_permalink($latest_post->ID);
				}
			}
		}
		
		$args = array(
        'taxonomy'     => 'category',  
        'orderby' => 'term_id',
        'order' => 'ASC',        // В порядке возрастания
        'hide_empty'   => false,          // Скрывать пустые категории
        'parent'       => 0,             // Получить только родительские категории
        'title_li'     => '',            // Убрать заголовок списка
        'exclude'    => $excluded_category_id, // Исключаем указанную категорию
        'walker'       => new Custom_Walker_Category_With_Post(),
        'latest_post_category_id' => $root_category_id,
        'latest_post_date' => $latest_post_date,
        'latest_post_url' => $latest_post_url
		);
		
		echo '<ul>';
		wp_list_categories($args);
		echo '</ul>';
		
		echo display_latest_posts_with_load_more();
	}
	// Кастомный Walker для вывода даты и времени последней записи
	class Custom_Walker_Category_With_Post extends Walker_Category {
		function start_el(&$output, $category, $depth = 0, $args = array(), $id = 0) {
			extract($args);
			$cat_name = esc_attr($category->name);
			$cat_id = (int) $category->term_id;
			
			$link = '<a href="' . esc_url(get_term_link($category)) . '" ';
			if ($use_desc_for_title == 0 || empty($category->description)) {
				$link .= 'title="' . esc_attr(sprintf(__('View all posts in %s', 'textdomain'), $cat_name)) . '">';
				} else {
				$link .= 'title="' . esc_attr(strip_tags(apply_filters('category_description', $category->description, $category))) . '">';
			}
			$link .= $cat_name . '</a>';
			
			// Проверяем, является ли эта категория корневой категорией последней записи
			if ($cat_id == $args['latest_post_category_id']) {
				$link .= ' <a href="' . esc_url($args['latest_post_url']) . '" class="latest-post-date">' . esc_html($args['latest_post_date']) . '</a>';
			}
			
			if (!empty($feed_image) || !empty($feed)) {
				$link .= ' ';
				if (empty($feed_image)) {
					$link .= '(';
				}
				$link .= '<a href="' . get_category_feed_link($category->term_id, $feed_type) . '"';
				if (empty($feed)) {
					$alt = ' alt="' . sprintf(__('Feed for all posts in %s', 'textdomain'), $cat_name) . '"';
					} else {
					$title = ' title="' . $feed . '"';
					$alt = ' alt="' . $feed . '"';
					$name = $feed;
					$link .= $title;
				}
				$link .= '>';
				if (!empty($feed_image)) {
					$link .= '<img src="' . $feed_image . '" style="border: none;"' . $alt . ' />';
					} else {
					$link .= $name;
				}
				$link .= '</a>';
				if (empty($feed_image)) {
					$link .= ')';
				}
			}
			
			if (isset($show_count) && $show_count) {
				$link .= ' (' . number_format_i18n($category->count) . ')';
			}
			if (isset($current_category) && $current_category) {
				$_current_category = get_term($current_category, $category->taxonomy);
			}
			if ('list' == $args['style']) {
				$output .= '<li class="cat-item cat-item-' . $cat_id;
				if (isset($current_category) && $current_category && ($category->term_id == $current_category)) {
					$output .= ' current-cat';
					} elseif (isset($_current_category) && $_current_category && ($category->term_id == $_current_category->parent)) {
					$output .= ' current-cat-parent';
				}
				$output .= '">';
				$output .= $link;
				} else {
				$output .= "\t" . $link . '<br />';
			}
		}
		
	}
	// Регистрация шорткода
	add_shortcode('parent_categories', 'display_parent_categories_with_latest_post_shortcode');
	
	// Функция для обработки AJAX-запросов
	function load_more_posts_ajax_handler() {
		$paged = isset($_POST['page']) ? intval($_POST['page']) : 1;
		
		$args = array(
        'post_type' => 'post',
        'post_status' => 'publish',
        'posts_per_page' => 12,
        'paged' => $paged,
		);
		
		$posts_query = new WP_Query($args);
		
		if ($posts_query->have_posts()) {
			while ($posts_query->have_posts()) {
				$posts_query->the_post();
				
				// Вывод записи
				$post_id = get_the_ID();
				$post_date = get_the_date('d.m.Y H:i', $post_id);
				$post_author = get_the_author();
				$post_link = get_permalink($post_id);
				$post_excerpt = get_the_excerpt($post_id);
				
				// Получение корневой родительской и дочерней рубрики
				$categories = get_the_category($post_id);
				$root_category = '';
				$child_category = '';
				
				if (!empty($categories)) {
					$root_category_obj = get_root_category($categories[0]->term_id);
					$root_category = $root_category_obj->name;
					
					foreach ($categories as $category) {
						if ($category->parent != 0) {
							$child_category = $category->name;
							break;
						}
					}
				}
				
				// Выводим запись
				echo '<li class="post-item">';
				echo '<span class="post-date">' . $post_date . '</span>';
				echo '<span class="post-author">' . $post_author . '</span>';
				echo '<span class="post-categories">' . $root_category . ' / ' . $child_category . '</span>';
				echo '<a href="' . $post_link . '">' . $post_excerpt . '</a>';
				echo '</li>';
			}
			wp_reset_postdata();
			} else {
			echo '';
		}
		
		wp_die();
	}
	add_action('wp_ajax_load_more_posts', 'load_more_posts_ajax_handler');
	add_action('wp_ajax_nopriv_load_more_posts', 'load_more_posts_ajax_handler');
	
	function display_latest_posts_with_load_more() {
		ob_start();
	?>
    <h2>Опубликованные записи:</h2>
    <ul id="posts-list">
        <?php
			$args = array(
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => 12,
            'paged' => 1,
			'meta_query' => array(
			array(
            'key'     => 'publish_post',  // Название метаполя
            'value'   => '1',             // Значение метаполя
            'compare' => '=',             // Сравнение
			),
			),
			);
			
			$posts_query = new WP_Query($args);
			
			if ($posts_query->have_posts()) {
				while ($posts_query->have_posts()) {
					$posts_query->the_post();
					
					// Вывод записи
					$post_id = get_the_ID();
					$post_date = get_the_date('d.m.Y H:i', $post_id);
					$post_author = get_the_author();
					$post_link = get_permalink($post_id);
					$post_excerpt = get_the_excerpt($post_id);
					
					// Получение корневой родительской и дочерней рубрики
					$categories = get_the_category($post_id);
					$root_category = '';
					$child_category = '';
					
					if (!empty($categories)) {
						$root_category_obj = get_root_category($categories[0]->term_id);
						$root_category = $root_category_obj->name;
						
						foreach ($categories as $category) {
							if ($category->parent != 0) {
								$child_category = $category->name;
								break;
							}
						}
					}
					
					// Выводим запись
					echo '<li class="post-item">';
					echo '<span class="post-date">' . $post_date . '</span>';
					echo '<span class="post-author">' . $post_author . '</span>';
					echo '<span class="post-categories">' . $root_category . ' / ' . $child_category . '</span>';
					echo '<a href="' . $post_link . '">' . $post_excerpt . '</a>';
					echo '</li>';
				}
				wp_reset_postdata();
			}
		?>
	</ul>
    <button id="load-more-posts">Показать еще</button>
    <script type="text/javascript">
        var myAjax = {
            ajaxurl: '<?php echo admin_url('admin-ajax.php'); ?>'
		};
	</script>
    <?php
		return ob_get_clean();
	}
	add_shortcode('latest_posts_with_load_more', 'display_latest_posts_with_load_more');
	
	
	// Редирект после авторизации
	function custom_login_redirect($redirect_to, $request, $user) {
		// Проверяем, что пользователь успешно авторизован
		if (isset($user->roles) && is_array($user->roles)) {
			return home_url(); // Перенаправляем на главную страницу
		}
		return $redirect_to;
	}
	add_filter('login_redirect', 'custom_login_redirect', 10, 3);
	
	// Редирект после выхода
	function custom_logout_redirect() {
		wp_redirect(home_url()); // Перенаправляем на главную страницу
		exit();
	}
	add_action('wp_logout', 'custom_logout_redirect');
	
	// Редирект после регистрации и автоматическая авторизация
	function custom_registration_redirect($user_id) {
		$user_info = get_userdata($user_id);
		$user_login = $user_info->user_login;
		
		if (!empty($_POST['user_pass'])) {
			wp_set_password($_POST['user_pass'], $user_id);
		}
		
		// Установка фиктивного email
		$fake_email = $user_id . '@example.com';
		wp_update_user(array(
        'ID' => $user_id,
        'user_email' => $fake_email
		));
		
		remove_action('user_register', 'wp_send_new_user_notifications');
		
		// Авторизация пользователя
		$user = get_user_by('login', $user_login);
		if ($user) {
			wp_set_current_user($user->ID);
			wp_set_auth_cookie($user->ID, true); // 'true' для того, чтобы пользователь оставался авторизованным
			do_action('wp_login', $user->user_login, $user);
		}
		
		// Перенаправление на главную страницу
		wp_redirect(home_url());
		exit();
	}
	add_action('user_register', 'custom_registration_redirect');
	
	
	
	/**
		* Функция для получения даты последней публикации записи (включая черновики) в указанной категории.
		*
		* @param int $category_id ID категории
		* @return string|bool Дата последней публикации в формате 'F j, Y' или false, если нет публикаций
	*/
	function get_last_post_date_in_category( $category_id ) {
		$args = array(
		'cat' => $category_id,
		'posts_per_page' => 1,
		'orderby' => 'date',
		'order' => 'DESC',
		'author' => get_current_user_id(),
		'post_status' => array( 'publish', 'draft' ), // Включаем черновики
		);
		
		$query = new WP_Query( $args );
		
		if ( $query->have_posts() ) {
			$query->the_post();
			$last_post_date = get_the_date( 'F j, Y' );
			wp_reset_postdata();
			return $last_post_date;
		}
		
		return false;
	}
	
	if ( ! function_exists( 'main_na_posted_on' ) ) {
		function main_na_posted_on() {
			$time_string = '<time class="entry-date published updated" datetime="%1$s">%2$s</time>';
			
			$time_string = sprintf( $time_string,
			esc_attr( get_the_date( DATE_W3C ) ),
			esc_html( get_the_date() )
			);
			
			$posted_on = sprintf(
			/* translators: %s: post date. */
			esc_html_x( 'Опубликовано %s', 'post date', 'text-domain' ),
			'<a href="' . esc_url( get_permalink() ) . '" rel="bookmark">' . $time_string . '</a>'
			);
			
			echo '<span class="posted-on">' . $posted_on . '</span>'; // WPCS: XSS OK.
		}
	}
	
	if ( ! function_exists( 'main_na_posted_by' ) ) {
		function main_na_posted_by() {
			$byline = sprintf(
			/* translators: %s: post author. */
			esc_html_x( 'автор %s', 'post author', 'text-domain' ),
			'<span class="author vcard"><a class="url fn n" href="' . esc_url( get_author_posts_url( get_the_author_meta( 'ID' ) ) ) . '">' . esc_html( get_the_author() ) . '</a></span>'
			);
			
			echo '<span class="byline"> ' . $byline . '</span>'; // WPCS: XSS OK.
		}
	}
	
	if ( ! function_exists( 'main_na_entry_footer' ) ) {
		function main_na_entry_footer() {
			// Hide category and tag text for pages.
			if ( 'post' === get_post_type() ) {
				/* translators: used between list items, there is a space after the comma */
				$categories_list = get_the_category_list( esc_html__( ', ', 'text-domain' ) );
				if ( $categories_list ) {
					/* translators: %s: list of categories. */
					printf( '<span class="cat-links">' . esc_html__( 'Вернуться в точку: %1$s', 'text-domain' ) . '</span>', $categories_list ); // WPCS: XSS OK.
				}
				
				/* translators: used between list items, there is a space after the comma */
				$tags_list = get_the_tag_list( '', esc_html__( ', ', 'text-domain' ) );
				if ( $tags_list ) {
					/* translators: %s: list of tags. */
					printf( '<span class="tags-links">' . esc_html__( 'Tagged %1$s', 'text-domain' ) . '</span>', $tags_list ); // WPCS: XSS OK.
				}
			}
			
			if ( ! is_single() && ! post_password_required() && ( comments_open() || get_comments_number() ) ) {
				echo '<span class="comments-link">';
				comments_popup_link( esc_html__( 'Leave a comment', 'text-domain' ), esc_html__( '1 Comment', 'text-domain' ), esc_html__( '% Comments', 'text-domain' ) );
				echo '</span>';
			}
			
			edit_post_link(
			sprintf(
			/* translators: %s: Name of current post. Only visible to screen readers */
			wp_kses(
			__( 'Edit <span class="screen-reader-text">%s</span>', 'text-domain' ),
			array(
			'span' => array(
			'class' => array(),
			),
			)
			),
			get_the_title()
			),
			'<span class="edit-link">',
			'</span>'
			);
		}
	}
	
	// Обработчик для получения полного содержимого записи
	function get_post_content() {
		// Получаем ID автора записи
		$post_author_id = (int)get_post_field('post_author', $_POST['post_id']);
		
		// Получаем ID текущего пользователя
		$current_user_id = get_current_user_id();
		
		// Проверяем, совпадает ли автор записи с текущим пользователем
		if ($post_author_id !== $current_user_id) {
			wp_send_json_error('Вы не можете редактировать эту запись.');
		}
		
		// Получаем полный контент записи
		$post = get_post( $_POST['post_id'] );
		if ( $post ) {
			wp_send_json_success( array( 'content' => $post->post_content ) );
			} else {
			wp_send_json_error();
		}
	}
	add_action( 'wp_ajax_get_post_content', 'get_post_content' );
	
	function delete_post() {
		// Получаем ID автора записи
		$post_author_id = (int)get_post_field('post_author', $_POST['post_id']);
		
		// Получаем ID текущего пользователя
		$current_user_id = get_current_user_id();
		
		// Проверяем, совпадает ли автор записи с текущим пользователем
		if ($post_author_id !== $current_user_id) {
			wp_send_json_error('Вы не можете удалить эту запись.');
		}
		
		// Удаляем запись
		if ( wp_delete_post( $_POST['post_id'], true ) ) {
			wp_send_json_success();
			} else {
			wp_send_json_error();
		}
	}
	add_action( 'wp_ajax_delete_post', 'delete_post' );
	
	function redirect_to_login_if_not_logged_in_on_category() {
		if (is_category() && !is_user_logged_in()) {
			// URL страницы регистрации/авторизации
			$login_url = wp_login_url(get_permalink());
			// Перенаправляем пользователя
			wp_redirect($login_url);
			exit;
		}
	}
	
	// Используем хук 'template_redirect' для выполнения нашей функции перед загрузкой шаблона
	add_action('template_redirect', 'redirect_to_login_if_not_logged_in_on_category');
	
	function custom_login_stylesheet() {
		wp_enqueue_style('custom-login', get_stylesheet_directory_uri() . '/custom-login.css');
	}
	
	add_action('login_enqueue_scripts', 'custom_login_stylesheet');
	// Удаление проверки на обязательность email и добавление кастомной проверки
	function custom_registration_errors($errors, $sanitized_user_login, $user_email) {
		if (empty($_POST['user_pass']) || empty($_POST['user_pass_confirm'])) {
			$errors->add('password_error', __('<strong>ERROR</strong>: Please enter a password and confirm it.'));
			} elseif ($_POST['user_pass'] != $_POST['user_pass_confirm']) {
			$errors->add('password_mismatch', __('<strong>ERROR</strong>: Passwords do not match.'));
		}
		
		// Снимаем ошибку, если поле email пустое
		unset($errors->errors['empty_email']);
		unset($errors->errors['invalid_email']);
		unset($errors->errors['existing_user_email']);
		
		return $errors;
	}
	add_filter('registration_errors', 'custom_registration_errors', 10, 3);
	
	// Скрытие поля email и добавление полей для пароля на форме регистрации
	function custom_registration_form() {
	?>
    <style>
        #registerform label[for="user_email"], #registerform input#user_email {
		display: none;
        }
	</style>
    <p>
        <label for="user_pass"><?php _e('Password') ?><br/>
		<input type="password" name="user_pass" id="user_pass" class="input" size="25" /></label>
	</p>
    <p>
        <label for="user_pass_confirm"><?php _e('Confirm Password') ?><br/>
		<input type="password" name="user_pass_confirm" id="user_pass_confirm" class="input" size="25" /></label>
	</p>
    <?php
	}
	add_action('register_form', 'custom_registration_form');
	
	
	// Отключение отправки email при регистрации
	function disable_new_user_notifications() {
		remove_action('register_new_user', 'wp_send_new_user_notifications');
		remove_action('edit_user_created_user', 'wp_send_new_user_notifications');
	}
	add_action('init', 'disable_new_user_notifications');
	
	// Предотвращение отправки email при регистрации пользователя
	/* function disable_wp_new_user_notification_email($user_id) {
		remove_action('user_register', 'wp_send_new_user_notifications');
		}
	add_action('user_register', 'disable_wp_new_user_notification_email', 1); */
	
	function save_for_sponsor_meta($post_id) {
		// Проверка для предотвращения лишних запусков
		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
		if (wp_is_post_revision($post_id)) return;
		
		// Сохранение мета-полей
		if (isset($_POST['for_sponsor'])) {
			update_post_meta($post_id, '_for_sponsor', '1');
			} else {
			delete_post_meta($post_id, '_for_sponsor');
		}
		
		if (isset($_POST['publish_post'])) {
			update_post_meta($post_id, 'publish_post', '1');
			} else {
			delete_post_meta($post_id, 'publish_post');
		}
		
		// Получение постоянной ссылки и отправка в Telegram
		$link = get_permalink($post_id);
		if (!empty($link)) {
			api_to_telegram($link);
		}
	}
	add_action('wp_insert_post', 'save_for_sponsor_meta');
	
	
	if (!current_user_can('administrator')) {
		add_filter('show_admin_bar', '__return_false');
	}
	
	function send_to_telegram() {
		// Проверяем, что пришли нужные поля
		if (empty($_POST['name']) || empty($_POST['text'])) {
			wp_send_json_error('Ошибка: Данные не были получены.');
			wp_die();
		}
		
		$name = sanitize_text_field($_POST['name']);
		$text = sanitize_textarea_field($_POST['text']);
		
		
		$message = "Имя: $name\nОписание: $text";
		
		$response = api_to_telegram($message);
		
		// Проверка на ошибки
		if (is_wp_error($response)) {
			wp_send_json_error('Ошибка отправки сообщения.');
			} else {
			wp_send_json_success('Сообщение успешно отправлено.');
		}
		
		wp_die();
	}
	
	
	add_action('wp_ajax_send_to_telegram', 'send_to_telegram');
	add_action('wp_ajax_nopriv_send_to_telegram', 'send_to_telegram');
	
	function api_to_telegram($message){
		// Токен и ID чата Telegram
		$telegram_token = '7869572806:AAFMqgkrodvf6yhhKrOH6frSI_d4-7P2AZY';
		$chat_id = '661000215';
		
		
		// Отправка запроса в Telegram
		$response = wp_remote_post("https://api.telegram.org/bot$telegram_token/sendMessage", [
        'body' => [
		'chat_id' => $chat_id,
		'text'    => $message
        ]
		]);
		
		return $response;
	}	
	
	//выводились записи только для авторов и администраторов
	add_action('pre_get_posts', function($query) {
    if (is_author() && $query->is_main_query() && !current_user_can('edit_others_posts')) {
        $query->set('author', get_current_user_id());
    }
});

add_action('template_redirect', function() {
    if (is_single() && !current_user_can('edit_others_posts')) {
        global $post;
        if ($post->post_author != get_current_user_id()) {
            wp_redirect(home_url()); // Перенаправляем на главную страницу
            exit;
        }
    }
});
