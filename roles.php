<?php
/**
Plugin Name: Role Master
Version: 1.0
Plugin URI: http://mpakfm.arvixe.ru/my_wp/14
Author: mpakfm
Author URI: http://mpakfm.arvixe.ru
Description: Настройка возможности писать пользователям в определенные категории. По умолчанию Авторы, Модераторы и Администраторы могут писать везде. Блоггеры нигде пока им не будет указано куда им можно оставлять сообщения. Исключение для Блоггеров составляет категория "Без Категории".
*/

register_activation_hook(__FILE__,'Plugin_Role_Master_setup_function');
register_deactivation_hook(__FILE__,'Plugin_Role_Master_unistall_function');
/**
* Бесполезная ф-ция установки. Но без неё WP напрямую через статичную ф-цию устанавливать плагин нехочет. 
* Пишет Notice на уровне исполнения action на установку что ему неизвестна переменная "Plugin_Role_Master::plugin_role_setup"
* Что приводит к выводу на экран фразы о том что Плгин конечно установлен но произвел ВНЕЗАПНЫЙ вывод на экран 126 символов.
*/
function Plugin_Role_Master_setup_function () {
	Plugin_Role_Master::plugin_role_setup();
}
function Plugin_Role_Master_unistall_function () {
	Plugin_Role_Master::plugin_role_unistall();
}

$oRole = Plugin_Role_Master::__init();
/**
* Класс плагина Role Master
* Создает роль Блоггер с возможностями Автора но с ограничем по категориям.
* Админка ограничий.
* @package WordPress
* @since 3.0
*/
class Plugin_Role_Master {
    public static $obj;
    public static $options_name = 'plugin_role_master';
    public $options = array();
    public $wpdb;
    public $now;
	/**
	* Singletone класса
	*/
    public static function __init () {
        return (self::$obj ? self::$obj : self::$obj = new self() );
    }
    /**
    * Метод установки плагина
    */
    public static function plugin_role_setup () {
        $option = get_option(self::$options_name);
        $WPR = new WP_Roles();
        if (false === $option) {
            # setup options:
            $option = array();
            $option['version'] = "1.0"; # plugin version
            $option['uninstall'] = false;
            add_option(self::$options_name, $option);
        }
        /** Add new Role */
		$capabilities = array(
			// Authors capabilities
			'edit_posts'=>1,
			'read'=>1,
			'delete_posts'=>1,
			'upload_files'=>1,
			'edit_published_posts'=>1,
			'publish_posts'=>1,
			'delete_published_posts'=>1,
			'level_1'=>1,
			'level_0'=>1,
			// Bloggers capabilities restrictions
			'post_term_restrictions'=>1
		);
        $res = $WPR->add_role( 'blogger', 'Blogger', $capabilities);
    }
    /**
    * Метод удаления плагина
    */
    public static function plugin_role_unistall () {
    	$WPR = new WP_Roles();
    	/** Remove Role */
		$WPR->remove_role('blogger');
        delete_option(self::$options_name);
    } 
    private function __clone() {}
    /**
    * Конструктор
    * Подключает БД, выгребает свойства из options, инициализирует фильтры и экшены
    */
    private function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->now = time();
        $this->options = get_option(self::$options_name);
        if (!empty($this->options))
        foreach ($this->options as $key => $val) {
            $this->$key = htmlspecialchars($val, ENT_QUOTES);
        }
        // Hook for adding admin menus
        add_action('admin_menu', array (&$this, 'admin_page'));
        // Translate
        add_filter('gettext_with_context',array(&$this,'translate'),10,4);
        // Roles in category
        add_filter('get_terms',array(&$this,'get_term'),10,3);
        // Low-level intercept
        add_filter ('query',array(&$this,'query_intercept'));
        
    }
    /**
    * Фильтр перевода имени роли
    * 
    * @param string $translations
    * @param string $text
    * @param string $context
    * @param string $domain
    * @return string
    */
    function translate ($translations, $text, $context, $domain) {
    	if (!(($text=='Blogger' || $text=='blogger')&&$context=='User role')) return $translations;
		return 'Блоггер';
    }
    /**
    * Низкоуровневый фильтр-перехватчик для реализации ограничений по категориям для различных пользователей
    * 
    * @param string $query
    * @return string
    */
    function query_intercept ($query) {
    	global $current_user;
    	if (!is_admin()) return $query;
		// tables
		$posts = $this->wpdb->posts;
		$comments = $this->wpdb->comments;
		$links = $this->wpdb->links;
		$term_taxonomy = $this->wpdb->term_taxonomy;
		$term_relationships = $this->wpdb->term_relationships;
		// roles
		$is_blogger = (isset($current_user->wp_capabilities['blogger'])?true:false);
		$is_author = (isset($current_user->wp_capabilities['author'])?true:false);
		// restrictions
		if (isset($current_user->allow_term_restrictions)) {
			$allow = unserialize($current_user->allow_term_restrictions);
		} else $allow = array();
    	if (isset($current_user->denied_term_restrictions)) {
			$denied = unserialize($current_user->denied_term_restrictions);
		}
		
		if (!$is_blogger && !isset($denied)) return $query;
		if (!empty($allow))
			$access = "IN (" . implode(",",$allow) . ")";
		if (!empty($denied))
			$access = "NOT IN (" . implode(",",$denied) . ")";
			
		// totals on edit.php
		// SELECT post_status, COUNT( * ) AS num_posts FROM wp_posts WHERE post_type = 'post' GROUP BY post_status
		if ( strpos($query, "ELECT post_status, COUNT( * ) AS num_posts ") && strpos($query, " FROM {$posts} WHERE post_type = 'post'") ) {
			$query = str_replace( "AND (post_status != 'private' OR ( post_author = '{$current_user->ID}' AND post_status = 'private' ))", '', $query);
			$query = str_replace( "post_status", "$posts.post_status", $query);
			$query = str_replace( "WHERE","LEFT JOIN {$term_relationships} rs ON rs.object_id = {$posts}.ID
			LEFT JOIN {$term_taxonomy} t ON rs.term_taxonomy_id = t.term_id AND t.taxonomy = 'category' WHERE t.taxonomy IS NOT NULL AND ",$query);
			$query = str_replace( "GROUP BY","AND ( rs.term_taxonomy_id ".(isset($access)?"{$access} || ":" IS NULL")."{$posts}.post_author = '{$current_user->ID}' ) GROUP BY",$query);
		}
		// show post
		// SELECT SQL_CALC_FOUND_ROWS  wp_posts.* FROM wp_posts  WHERE 1=1  AND wp_posts.post_type = 'post' AND (wp_posts.post_status = 'publish')  ORDER BY wp_posts.post_date DESC LIMIT 0, 20
		if (strpos($query, "ELECT SQL_CALC_FOUND_ROWS  {$posts}.* FROM {$posts}") && strpos($query, " AND {$posts}.post_type = 'post'")) {
			// MY: SELECT SQL_CALC_FOUND_ROWS  wp_posts.* FROM wp_posts  WHERE 1=1  AND (wp_posts.post_author = 3) AND wp_posts.post_type = 'post' AND (wp_posts.post_status = 'publish' OR wp_posts.post_status = 'future' OR wp_posts.post_status = 'draft' OR wp_posts.post_status = 'pending' OR wp_posts.post_author = 3 AND wp_posts.post_status = 'private')  ORDER BY wp_posts.post_date DESC LIMIT 0, 20
			if (strpos($query,  "AND ({$posts}.post_author = {$current_user->ID})" )) return $query; // возможен баг с переносом авторского текста в чужую категорию. хз че будет если автор попробует этот текст потом поправить. - текст правится и попадеат в "Без категории". Надо запретить входить на правку в чужую категорию даже если это твой пост.
			
			// PUBLISH: SELECT SQL_CALC_FOUND_ROWS  wp_posts.* FROM wp_posts  WHERE 1=1  AND wp_posts.post_type = 'post' AND (wp_posts.post_status = 'publish')  ORDER BY wp_posts.post_date DESC LIMIT 0, 20
			//$access = implode(",",unserialize($current_user->allow_term_restrictions));
			if (strpos($query,  "({$posts}.post_status = 'publish')  ORDER" )) {
				$query = str_replace( "WHERE","LEFT JOIN {$term_relationships} rs ON rs.object_id = {$posts}.ID 
				LEFT JOIN {$term_taxonomy} t ON rs.term_taxonomy_id = t.term_id AND t.taxonomy = 'category' WHERE t.taxonomy IS NOT NULL AND ",$query);
				$query = str_replace( "ORDER BY","AND ( rs.term_taxonomy_id ".(isset($access)?" {$access}":"IS NULL")." ) ORDER BY",$query);
			}
			
			// DRAFT: SELECT SQL_CALC_FOUND_ROWS  wp_posts.* FROM wp_posts  WHERE 1=1  AND wp_posts.post_type = 'post' AND (wp_posts.post_status = 'draft')  ORDER BY wp_posts.post_modified DESC LIMIT 0, 20
			if (strpos($query,  "({$posts}.post_status = 'draft')  ORDER" )) {
				if (!$is_blogger && !$is_author) return $query;
				$query = str_replace( "WHERE","LEFT JOIN {$term_relationships} rs ON rs.object_id = {$posts}.ID 
				LEFT JOIN {$term_taxonomy} t ON rs.term_taxonomy_id = t.term_id AND t.taxonomy = 'category' WHERE t.taxonomy IS NOT NULL AND ",$query);
				$query = str_replace( "ORDER BY","AND {$posts}.post_author = '{$current_user->ID}' ORDER BY",$query);
			}
			
			// TRASH: SELECT SQL_CALC_FOUND_ROWS  wp_posts.* FROM wp_posts  WHERE 1=1  AND wp_posts.post_type = 'post' AND (wp_posts.post_status = 'trash')  ORDER BY wp_posts.post_date DESC LIMIT 0, 20
			if (strpos($query,  "({$posts}.post_status = 'trash')  ORDER" )) { 
				if (!$is_blogger && !$is_author) return $query;
				$query = str_replace( "WHERE","LEFT JOIN {$term_relationships} rs ON rs.object_id = {$posts}.ID 
				LEFT JOIN {$term_taxonomy} t ON rs.term_taxonomy_id = t.term_id AND t.taxonomy = 'category' WHERE t.taxonomy IS NOT NULL AND ",$query);
				$query = str_replace( "ORDER BY","AND {$posts}.post_author = '{$current_user->ID}' ORDER BY",$query);
			}
			
			// ALL: SELECT SQL_CALC_FOUND_ROWS  wp_posts.* FROM wp_posts  WHERE 1=1  AND wp_posts.post_type = 'post' AND (wp_posts.post_status = 'publish' OR wp_posts.post_status = 'future' OR wp_posts.post_status = 'draft' OR wp_posts.post_status = 'pending' OR wp_posts.post_author = 3 AND wp_posts.post_status = 'private')  ORDER BY wp_posts.post_date DESC LIMIT 0, 20
			// ALL: SELECT SQL_CALC_FOUND_ROWS  wp_posts.* FROM wp_posts  WHERE 1=1  AND wp_posts.post_type = 'post' AND (wp_posts.post_status = 'publish' OR wp_posts.post_status = 'future' OR wp_posts.post_status = 'draft' OR wp_posts.post_status = 'pending' OR wp_posts.post_status = 'private')  ORDER BY wp_posts.post_date DESC LIMIT 0, 20

			if (strpos($query,  "{$posts}.post_status = 'publish'") && strpos($query,  "{$posts}.post_status = 'future'") && strpos($query,  "{$posts}.post_status = 'draft'") && strpos($query,  "{$posts}.post_status = 'pending'") ) {
				$query = str_replace( "WHERE","LEFT JOIN {$term_relationships} rs ON rs.object_id = {$posts}.ID 
				LEFT JOIN {$term_taxonomy} t ON rs.term_taxonomy_id = t.term_id AND t.taxonomy = 'category' WHERE t.taxonomy IS NOT NULL AND ",$query);
				$query = str_replace( "ORDER BY","AND ( rs.term_taxonomy_id ".(isset($access)?" {$access}":"IS NULL")." || {$posts}.post_author = '{$current_user->ID}') ORDER BY",$query);
			}
		}
		return $query;
    }
    /**
    * Низкоуровненвый фильтр-перехватчик для показа категорий в админке в зависимости от ограничений
    * 
    * @param array $terms
    * @param string $taxonomies
    * @param array $args
    * @return array
    */
    function get_term($terms,$taxonomies='',$args=array()) {
    	global $current_user;
    	if (!is_admin() || (isset($args['role_admin']) && $args['role_admin']==1) ) return $terms;
    	
    	$result = array();
    	$is_blogger = current_user_can('post_term_restrictions');
    	//printu($current_user);
    	if (isset($current_user->allow_term_restrictions)) {
			$allow = unserialize($current_user->allow_term_restrictions);
		} else $allow = array();
    	if (isset($current_user->denied_term_restrictions)) {
			$denied = unserialize($current_user->denied_term_restrictions);
		}
    	if ($is_blogger || isset($denied)) {
			foreach ($terms as &$term) {
				if (!is_object($term)) continue;
				if ( ($is_blogger && in_array($term->term_id,$allow) ) || 
				(isset($denied) && !in_array($term->term_id,$denied)) )
					$result[] = $term;
			}
    	} else return $terms;
		return $result;	
	}
	/**
	* Регистрация страницы в меню и админке пользователей
	*/
    public function admin_page() {
        add_submenu_page('users.php', 'Роли', 'Роли', 'administrator', 'roles', array(&$this,'roles_admin'));
    }
    /**
    * GET обработчик админки плагина
    */
    public function roles_admin () {
    	$WPR = new WP_Roles();
    	// POST
    	if (!empty($_POST)) $this->post_admin();
    	// Query the user IDs for this page
		$usersearch = isset($_GET['usersearch']) ? $_GET['usersearch'] : null;
		$userspage = isset($_GET['userspage']) ? $_GET['userspage'] : null;
		$role = isset($_GET['role']) ? $_GET['role'] : null;
		$wp_user_search = new WP_User_Search($usersearch, $userspage, $role);
		$us_counts = count($wp_user_search->results);
		
    	?>
    	<div class="wrap">
			<?php screen_icon(); ?><h2>Роли</h2>
			<span class="subtitle">Настройка возможности писать пользователям в определенные категории. По умолчанию Авторы, Модераторы и Администраторы могут писать везде. Блоггеры нигде пока им не будет указано куда им можно оставлять сообщения. Исключение для Блоггеров составляет категория "Без Категории".</span
			<? if (isset($_SESSION['role_admin']['error'])) : ?>
				<div class="error">
					<ul><? foreach ($_SESSION['role_admin']['error'] as $e) print "<li>{$e}</li>"; ?></ul>
				</div>
			<? endif; ?>
			<? if (isset($_SESSION['role_admin']['msg'])) : ?>
			<div id="message" class="updated">
				<? foreach ($_SESSION['role_admin']['msg'] as $m) print "<p><strong>{$m}</strong></p>"; ?>
			</div>
			<? endif; ?>
			<div style="padding: 10px 0 10px 20px;">
				<? if (!isset($_GET['user_id'])) : ?>
					<script language="javascript">
					jQuery(document).ready(function() {
						jQuery('#user_select').bind('change',function(){
							window.location = '?page=roles&user_id='+jQuery('#user_select').val();
						});
					});
					</script>
					<p><strong>Выберите пользователя: </strong>
						<select id="user_select">
							<option value="0"> - выбрать - </option>
							<? for ($i=0; $i < $us_counts; $i++) :?>
								<? $wp_user = get_userdata($wp_user_search->results[$i]);?>
								<? $u_roles = array(); foreach ($wp_user->wp_capabilities as $role=>$v) { $u_roles[] = translate_user_role($WPR->role_names[$role]); } ?>
								<option value="<?=$wp_user_search->results[$i];?>"> <?=$wp_user->display_name; ?> (<?=implode(", ",$u_roles);?>)</option>
							<? endfor; ?>
	?>
						</select>
					</p>
				<? else: ?>
				<?
					$user = get_userdata((int)$_GET['user_id']);
					$u_roles = array(); foreach ($user->wp_capabilities as $role=>$v) { $u_roles[] = translate_user_role($WPR->role_names[$role]); }
					//printu($user);
					if (isset($user->allow_term_restrictions)) {
						$this->allow = unserialize($user->allow_term_restrictions);
					} elseif (!isset($user->allow_term_restrictions) && isset($user->wp_capabilities['blogger']) ) {
						$this->allow = false;
					} else $this->allow = true;
					if (isset($user->denied_term_restrictions)) {
						$this->denied = unserialize($user->denied_term_restrictions);
					} else $this->denied = false;
					//printu(($this->allow?($this->allow===true?'true':$this->allow):'false'),'$allow');
					//printu(($this->denied?($this->denied===true?'true':$this->denied):'false'),'$denied');
				?>
					<p><strong>Принцип действия ограничений.</strong></p>
					<p>&nbsp;&nbsp;&nbsp;&nbsp;Роли: Автор, Модератор и Администратор имеют право по умолчанию постить в любую категорию, поэтому для них основной фактор ограничения это "Запрет". Работает по принципу - разрешено все, что не запрещено.<br />
					&nbsp;&nbsp;&nbsp;&nbsp;Роль Блоггер наоборот - по умолчанию запрещено постить везде и необходимо отметить те категории куда постить можно. Не отмеченные категории естественно по-умолчанию запрещены, и отмечать их дополнительно на "Запрет" необязательно.<br />
					&nbsp;&nbsp;&nbsp;&nbsp;Роли Подписчик и Участник на уровне Вордпресса постить не имеют права и ставить этим пользователям права постинга в категории вообще бесмысленно.</p>
					<p><strong><?=$user->display_name;?></strong> - <?=implode(", ",$u_roles);?></p>
					<p><strong><div style="float:left;width:200px;">Категории</div><span>Разрешено</span> <span>Запрещено</span></strong></p>
					<?
						$cat = get_terms('category',array('get' => 'all','role_admin'=>'1'));
						$this->child = array();
						$parent = array();
						foreach ($cat as &$c) {
							if ($c->parent != 0) {
								$this->child[$c->parent][] = $c;
							} else $parent[] = $c;
						}
						?>
						<form action="" name="restriction" method="POST" class="">
							<input type="hidden" name="user_id" value="<?=$user->ID;?>" />
							<?
							$this->show_tree_cat ($parent);
							?>
							<p style="padding-left: 100px;"><input type="submit" name="submit" value="Сохранить" /></p>
						</form><?
					?>
				<? endif; ?>
			</div>	
		</div>
    	<?
        //$WPR = new WP_Roles();
        //printu($WPR->role_names,'role_names');
        //printu($WPR->roles,'roles');
    }
    /**
    * Древовидный вывод категорий для админки плагина с двумя радио-баттонами
    * 
    * @param array $parent
    * @param int $padd
    */
    function show_tree_cat ($parent, $padd=0) {
		foreach ($parent as &$c) : ?>
			<p><div style="float:left;width:<?=(240-$padd);?>px; padding-left: <?=(0+$padd);?>px;"><?=$c->name;?></div>
			<div style="width: 70px; float: left;">
			<input type="radio" name="cat_<?=$c->term_id;?>" value="1" <?=((is_array($this->allow) && !empty($this->allow) && in_array($c->term_id,$this->allow)) && (!$this->denied || (!in_array($c_id->term_id,$this->denied)) )?'checked':'');?> /></div> 
			<span>
			<input type="radio" name="cat_<?=$c->term_id;?>" value="0" <?=((!empty($this->denied) && in_array($c->term_id,$this->denied))?'checked':'');?> /></span></p>
			<? if (isset($this->child[$c->term_id]))  $this->show_tree_cat($this->child[$c->term_id],($padd+20)); ?>
		<? endforeach; 
    }
    /**
    * POST-обработчик админки плагина
    */
    function post_admin () {
    	$_SESSION['role_admin']['error'] = array();
		$user_id = (int)(isset($_POST['user_id'])?$_POST['user_id']:0);
		unset($_POST['user_id']);
		if ($user_id==0) {
			$_SESSION['role_admin']['error'][] = 'Не выбран пользователь';
		}
		if (!isset($_POST['submit'])) {
			$_SESSION['role_admin']['error'][] = 'Неопознанное действие';
		}
		unset($_POST['submit']);
		$term = array();
		foreach ($_POST as $key=>$val) {
			$term[(int)str_replace('cat_','',$key)] = (int)$val;
		}
		$user = get_userdata($user_id);
		if (!$user) {
			$_SESSION['role_admin']['error'][] = 'Не найден пользователь';
			//header ("Location: ?page=roles");
			?><script>window.loaction = '?page=roles'; </script><?
			die;
		}
		if (isset($user->wp_capabilities['administrator']) || isset($user->wp_capabilities['editor']) || isset($user->wp_capabilities['author'])) {
			$denied = array();
			if (!empty($term))
			foreach ($term as $c=>$v) {
				if ($v==0) $denied[] = $c;
			}
			$term_restrictions = serialize($denied);
			update_metadata('user',$user->ID,'denied_term_restrictions',$term_restrictions);
			$_SESSION['role_admin']['msg'][] = 'Новые ограничения установлены';
		} elseif (isset($user->wp_capabilities['blogger'])) {
			$allow = array();
			if (!empty($term))
			foreach ($term as $c=>$v) {
				if ($v==1) $allow[] = $c;
			}
			$term_restrictions = serialize($allow);
			update_metadata('user',$user->ID,'allow_term_restrictions',$term_restrictions);
			$_SESSION['role_admin']['msg'][] = 'Новые ограничения установлены';
		} else $_SESSION['role_admin']['error'][] = 'Роль этого пользователя не предусматривает использование ограничений по категориям';
		//header ("Location: ?page=roles&user_id=".$user->ID);
		?><script>window.loaction = '?page=roles&user_id=<?=$user->ID;?>'; </script><?
    }
}
?>