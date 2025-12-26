<?php get_header(); ?>

<div id="primary" class="content-area full-width">
    <main id="main" class="site-main">
        
        <header class="page-header">
            <?php
                // Display breadcrumbs
                if ( function_exists( 'bcn_display' ) ) {
                    echo '<div class="breadcrumbs" typeof="BreadcrumbList" vocab="https://schema.org/">';
                    bcn_display();
                    echo '</div>';
				}
                the_archive_description( '<div class="archive-description">', '</div>' );
				
				$description = get_the_archive_description();
				if ( $description ) {
				?>
				<button id="toggle-recommendations" class="toggle-button">
					<i class="fa fa-arrow-down"></i> Описание
				</button>
				<?php
				}
				
				echo '<button id="increase-font" class="increase-font-button">';
				echo '<i class="fa fa-plus"></i> Увеличить текст';
				echo '</button>';
				
                // Display archive title without "Category:" prefix (hidden on mobile)
                $archive_title = single_cat_title( '', false );
                echo '<h1 class="page-title">' . esc_html( $archive_title ) . '</h1>';
				
			?>
		</header><!-- .page-header -->
        
        <?php
            // Get the current category
            $current_category = get_queried_object();
            
            // Get direct child categories of the current category
			$child_categories = get_categories( array(
			'parent' => $current_category->term_id,
			'hide_empty' => false,
			'orderby' => 'term_id',
			'order' => 'ASC',
			) );
			
			if ( !empty( $child_categories ) ) {
				echo '<ul class="child-categories">';
				foreach ( $child_categories as $child_category ) {
					echo '<li class="child-category">';
					echo '<h2><a href="' . get_category_link( $child_category->term_id ) . '">' . esc_html( $child_category->name ) . '</a></h2>';
					
					// Display description of child category if it exists
					if ( !empty( $child_category->description ) ) {
						echo '<div class="archive-description">' .  term_description($child_category->term_id)  . '</div>';
						
					}
					
					// Get subcategories of the child category
					$sub_categories = get_categories( array(
					'parent' => $child_category->term_id,
					'hide_empty' => false,
					'orderby' => 'term_id',
					'order' => 'ASC',
					) );
					
					if ( !empty( $sub_categories ) ) {
						echo '<ul class="sub-categories">';
						foreach ( $sub_categories as $sub_category ) {
							$category_link = get_category_link( $sub_category->term_id );
							$category_name = esc_html( $sub_category->name );
							$category_description = !empty( $sub_category->description ) ? '<p class="archive-description">' . esc_html( $sub_category->description ) . '</p>' : '';
							
							// Получаем количество всех записей в подкатегории, включая черновики
							$all_posts_count = new WP_Query( array(
							'cat' => $sub_category->term_id,
							'post_status' => array( 'publish', 'draft', 'pending', 'private' ), // Включаем черновики и другие статусы
							'author' => get_current_user_id(),
							'posts_per_page' => -1 // Получаем все записи
							) );
							$post_count = $all_posts_count->found_posts != 0 ? ' (' .$all_posts_count->found_posts. ')' : '';
							
							// Получаем дату последней публикации в подкатегории
							$latest_post_date = get_last_post_date_in_category( $sub_category->term_id );
							
							echo '<li class="sub-category">';
							echo '<a href="' . $category_link . '">' . $category_name .' '. $post_count .'</a>';
							
							if ( !empty( $latest_post_date ) ) {
								echo '<span class="post-count"> ' . $latest_post_date . '</span>';
							}
							
							echo $category_description;
							echo '</li>';
						}
						echo '</ul>';
						}else{
						// Display all posts in the current category visible to the current user
						$args = array(
						'cat' => $child_category->term_id,
						'post_type' => 'post',
						'posts_per_page' => -1, // Display all posts
						'author' => get_current_user_id(),
						'post_status' => array('publish', 'draft'),
						'orderby' => 'date', // Сортировка по дате
						'order' => 'ASC'   // В обратном порядке (новые сначала)
						);
						$posts_query = new WP_Query($args);
						
						if ($posts_query->have_posts()) {
							echo '<h2>Ваши записи</h2>';
							echo '<ul class="posts-list">';
							while ($posts_query->have_posts()) {
								$posts_query->the_post();
								$post_id = get_the_ID();
							?>
							<li class="post-item" data-post-id="<?php echo get_the_ID(); ?>">
								<a href="<?php the_permalink(); ?>"><?php the_content(); ?></a>
								<p class="post-status <?php echo (get_post_meta($post_id, 'publish_post') == 1) ? 'published' : 'private'; ?>">
									<?php echo (get_post_meta($post_id, 'publish_post') == 1) ? 'Опубликовано' : 'Личное'; ?>
								</p>
								<?php
									if($is_for_sponsor = get_post_meta($post_id, '_for_sponsor', true)){
									?>
									<p class="post-status published">
										Для спонсора
									</p>
									<?php
									}
									
									// Проверяем, была ли запись создана из Telegram
									if (class_exists('TCM_Telegram')) {
										$telegram = new TCM_Telegram();
										if ($telegram->is_post_from_telegram($post_id)) {
											$telegram_created_at = get_post_meta($post_id, '_telegram_created_at', true);
											?>
											<p class="post-status published" style="color: #0088cc;">
												<i class="fa fa-paper-plane"></i> Создано из Telegram
												<?php if ($telegram_created_at): ?>
													(<?php echo date('d.m.Y H:i', strtotime($telegram_created_at)); ?>)
												<?php endif; ?>
											</p>
											<?php
										}
									}
								?>
								<p class="post-date">Дата публикации: <?php echo get_the_date('d.m.Y H:i'); ?></p>
							</li>
							<?php
							}
							echo '</ul>';
							wp_reset_postdata();
						}
					}
					
					
					
					
					echo '</li>';
				}
				echo '</ul>';
				} else {
				// Show form to publish a new post if there are no child categories
			?>
			<div class="new-post-form">
				<form id="post-form" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" method="post">
					<input type="hidden" name="action" value="create_new_post">
					<input type="hidden" name="category_id" value="<?php echo esc_attr( $current_category->term_id ); ?>">
					<input type="hidden" name="post_id" value="">
					<input type="hidden" name="post_title" value="">
					<textarea name="post_content" id="post-content" rows="10" required></textarea>
					<div class="form-controls">
						<label for="publish-post">Опубликовать</label>
						<input type="checkbox" name="publish_post" id="publish-post" value="1">
						
						<label for="for-sponsor">Для Спонсора</label>
						<input type="checkbox" name="for_sponsor" id="for-sponsor" value="1">
						
						
						<input type="submit" value="Сохранить">
					</div>
					
					<?php
						// Function to find the next sibling category
						function get_next_sibling_category($current_category_id) {
							// Get the parent category
							$parent_category = get_category($current_category_id)->parent;
							
							if ($parent_category) {
								// Get sibling categories
								$siblings = get_categories(array(
								'parent' => $parent_category,
								'hide_empty' => false,
								'orderby' => 'term_id',
								'order' => 'ASC'
								));
								
								// Find the current category and get the next sibling
								foreach ($siblings as $index => $sibling) {
									if ($sibling->term_id == $current_category_id) {
										if (isset($siblings[$index + 1])) {
											return $siblings[$index + 1];
										}
										break;
									}
								}
							}
							return null;
						}
						
						// Get the next sibling category or the next parent's sibling category
						$next_category = get_next_sibling_category($current_category->term_id);
						
						if (!$next_category && $current_category->parent) {
							// If no next sibling and has a parent, get the parent's next sibling
							$parent_category_id = get_category($current_category->parent)->term_id;
							$next_parent_category = get_next_sibling_category($parent_category_id);
							if ($next_parent_category) {
								// Get the first child of the next parent category
								$next_category_children = get_categories(array(
								'parent' => $next_parent_category->term_id,
								'hide_empty' => false,
								'orderby' => 'term_id',
								'order' => 'ASC'
								));
								$next_category = !empty($next_category_children) ? $next_category_children[0] : null;
							}
						}
						
						// Display the link to the next category
						if ($next_category) {
							$parent_category = get_category($next_category->parent);
							echo '<div class="next-category">';
							if ($parent_category) {
								echo '<a href="' . get_category_link($parent_category->term_id) . '">' . esc_html($parent_category->name) . '</a> &gt; ';
							}
							echo '<a href="' . get_category_link($next_category->term_id) . '">Следующая точка: ' . esc_html($next_category->name) . '</a>';
							echo '</div>';
						}
					?>
				</form>
			</div>
			
			<script>
			(function() {
				var categoryId = <?php echo esc_js( $current_category->term_id ); ?>;
				var storageKey = 'post_content_category_' + categoryId;
				var textarea = document.getElementById('post-content');
				var form = document.getElementById('post-form');
				
				if (textarea) {
					// Восстанавливаем сохраненное содержимое при загрузке страницы
					var savedContent = localStorage.getItem(storageKey);
					if (savedContent) {
						textarea.value = savedContent;
					}
					
					// Сохраняем содержимое в localStorage при каждом изменении
					textarea.addEventListener('input', function() {
						localStorage.setItem(storageKey, textarea.value);
					});
					
					// Очищаем localStorage после успешной отправки формы
					form.addEventListener('submit', function() {
						// Используем небольшую задержку, чтобы убедиться, что форма отправлена
						setTimeout(function() {
							localStorage.removeItem(storageKey);
						}, 100);
					});
					
					// Также сохраняем при уходе со страницы (beforeunload)
					window.addEventListener('beforeunload', function() {
						localStorage.setItem(storageKey, textarea.value);
					});
				}
			})();
			</script>
			<?php
				
				// Display all posts in the current category visible to the current user
				$args = array(
				'cat' => $current_category->term_id,
				'post_type' => 'post',
				'posts_per_page' => -1, // Display all posts
				'author' => get_current_user_id(),
				'post_status' => array('publish', 'draft'),
				);
				$posts_query = new WP_Query($args);
				
				if ($posts_query->have_posts()) {
					echo '<h2>Ваши записи</h2>';
					echo '<ul class="posts-list">';
					while ($posts_query->have_posts()) {
						$posts_query->the_post();
						$post_id = get_the_ID();
					?>
					<li class="post-item" data-post-id="<?php echo get_the_ID(); ?>">
						<a href="<?php the_permalink(); ?>"><?php the_excerpt(); ?></a>
						<p class="post-status <?php echo (get_post_meta($post_id, 'publish_post') == 1) ? 'published' : 'private'; ?>">
							<?php echo (get_post_meta($post_id, 'publish_post') == 1) ? 'Опубликовано' : 'Личное'; ?>
						</p>
						<?php
							if($is_for_sponsor = get_post_meta($post_id, '_for_sponsor', true)){
							?>
							<p class="post-status published">
								Для спонсора
							</p>
							<?php
							}
							
							// Проверяем, была ли запись создана из Telegram
							if (class_exists('TCM_Telegram')) {
								$telegram = new TCM_Telegram();
								if ($telegram->is_post_from_telegram($post_id)) {
									$telegram_created_at = get_post_meta($post_id, '_telegram_created_at', true);
									?>
									<p class="post-status published" style="color: #0088cc;">
										<i class="fa fa-paper-plane"></i> Создано из Telegram
										<?php if ($telegram_created_at): ?>
											(<?php echo date('d.m.Y H:i', strtotime($telegram_created_at)); ?>)
										<?php endif; ?>
									</p>
									<?php
								}
							}
						?>
						<p class="post-date">Дата публикации: <?php echo get_the_date('d.m.Y H:i'); ?></p>
						<?php if (get_current_user_id() == get_the_author_meta('ID')) { ?>
							<button class="edit-post" data-post-id="<?php echo get_the_ID(); ?>">Изменить</button>
							<button class="delete-post" data-post-id="<?php echo get_the_ID(); ?>" style="background: none; border: none; color: inherit; cursor: pointer;">Удалить</button>
						<?php } ?>
					</li>
					<?php
					}
					echo '</ul>';
					wp_reset_postdata();
				}
				
				// Display all published posts in the current category
				$published_args = array(
				'cat' => $current_category->term_id,
				'post_type' => 'post',
				'posts_per_page' => -1, // Display all posts
				'post_status' => 'publish',
				'author' => -1,
				'meta_query' => array(
				array(
				'key'     => 'publish_post',  // Название метаполя
				'value'   => '1',             // Значение метаполя
				'compare' => '=',             // Сравнение
				),
				),
				);
				$published_query = new WP_Query($published_args);
				if ($published_query->have_posts()) {
					echo '<h2>Опубликованные записи</h2>';
					echo '<ul class="posts-list">';
					while ($published_query->have_posts()) {
						$published_query->the_post();
					?>
					<li class="post-item" data-post-id="<?php echo get_the_ID(); ?>">
						<a href="<?php the_permalink(); ?>"><?php the_excerpt(); ?></a>
						<p class="post-date">Дата публикации: <?php echo get_the_date('d.m.Y H:i'); ?></p>
						<p class="post-author">Автор: <?php echo get_the_author_meta('user_login'); ?></p>
					</li>
					<?php
					}
					echo '</ul>';
					wp_reset_postdata();
				}
				
			}
		?>
		
	</main><!-- #main -->
</div><!-- #primary -->

<?php get_footer(); ?>
