<?php
	add_action( 'acf/include_fields', function() {
		if ( ! function_exists( 'acf_add_local_field_group' ) ) {
			return;
		}
		
		acf_add_local_field_group( array(
		'key' => 'group_669e216e11ae9',
		'title' => 'Запись План',
		'fields' => array(
		array(
		'key' => 'field_669e216ea55fa',
		'label' => 'Запись План',
		'name' => 'zapis_plan',
		'aria-label' => '',
		'type' => 'group',
		'instructions' => '',
		'required' => 0,
		'conditional_logic' => 0,
		'wrapper' => array(
		'width' => '',
		'class' => '',
		'id' => '',
		),
		'layout' => 'block',
		'sub_fields' => array(
		array(
		'key' => 'field_669e21aaa55fb',
		'label' => 'План',
		'name' => 'plan',
		'aria-label' => '',
		'type' => 'wysiwyg',
		'instructions' => '',
		'required' => 0,
		'conditional_logic' => 0,
		'wrapper' => array(
		'width' => '',
		'class' => '',
		'id' => '',
		),
		'default_value' => '',
		'tabs' => 'all',
		'toolbar' => 'full',
		'media_upload' => 1,
		'delay' => 0,
		),
		array(
		'key' => 'field_669e21bba55fc',
		'label' => 'Задачи',
		'name' => 'zadachi',
		'aria-label' => '',
		'type' => 'textarea',
		'instructions' => '',
		'required' => 0,
		'conditional_logic' => 0,
		'wrapper' => array(
		'width' => '',
		'class' => '',
		'id' => '',
		),
		'default_value' => '',
		'maxlength' => '',
		'rows' => '',
		'placeholder' => '',
		'new_lines' => '',
		),
		),
		),
		),
		'location' => array(
		array(
		array(
		'param' => 'post_type',
		'operator' => '==',
		'value' => 'post',
		),
		),
		),
		'menu_order' => 0,
		'position' => 'normal',
		'style' => 'default',
		'label_placement' => 'top',
		'instruction_placement' => 'label',
		'hide_on_screen' => '',
		'active' => true,
		'description' => '',
		'show_in_rest' => 0,
		) );
	} );
	
	add_action( 'init', function() {
		register_post_type( 'plan', array(
		'labels' => array(
		'name' => 'План на день',
		'singular_name' => 'Мой план',
		'menu_name' => 'План',
		'all_items' => 'Все План',
		'edit_item' => 'Изменить Мой план',
		'view_item' => 'Посмотреть Мой план',
		'view_items' => 'Посмотреть План',
		'add_new_item' => 'Добавить новое Мой план',
		'new_item' => 'Новый Мой план',
		'parent_item_colon' => 'Родитель Мой план:',
		'search_items' => 'Поиск План',
		'not_found' => 'Не найдено план',
		'not_found_in_trash' => 'В корзине не найдено план',
		'archives' => 'Архивы Мой план',
		'attributes' => 'Атрибуты Мой план',
		'insert_into_item' => 'Вставить в мой план',
		'uploaded_to_this_item' => 'Загружено в это мой план',
		'filter_items_list' => 'Фильтровать список план',
		'filter_by_date' => 'Фильтр план по дате',
		'items_list_navigation' => 'План навигация по списку',
		'items_list' => 'План список',
		'item_published' => 'Мой план опубликовано.',
		'item_published_privately' => 'Мой план опубликована приватно.',
		'item_reverted_to_draft' => 'Мой план преобразован в черновик.',
		'item_scheduled' => 'Мой план запланировано.',
		'item_updated' => 'Мой план обновлён.',
		'item_link' => 'Cсылка на Мой план',
		'item_link_description' => 'Ссылка на мой план.',
		),
		'public' => true,
		'show_in_rest' => true,
		'supports' => array(
		0 => 'title',
		1 => 'editor',
		2 => 'thumbnail',
		),
		'delete_with_user' => false,
		) );
	} );
	
