<?php
	/**
	 * Created by tokapps TM.
	 * Programmer: gholamreza beheshtian
	 * Mobile:09353466620
	 * Company Phone:05138846411
	 * Site:http://tokapps.ir
	 * Date: 03/22/2020
	 * Time: 17:13 PM
	 */
	
	namespace system\lib\i18n;
	
	
	use Yii;
	use function file_get_contents;
	use function json_decode;
	
	class Layout {
		public static $layout = '';
		
		public static function run() {
			if ( ! empty( self::$layout ) ) {
				return self::$layout;
			}
			$content      = file_get_contents( __DIR__ . '/layouts.json' );
			$content      = json_decode( $content );
			$content      = $content->{Yii::$app->language};
			self::$layout = $content;
			
			return $content;
		}
		
		public static function date() {
			if ( ! empty( self::$layout ) ) {
				return self::$layout;
			}
			$content      = file_get_contents( __DIR__ . '/date.json' );
			$content      = json_decode( $content );
			$content      = $content->{Yii::$app->language};
			self::$layout = $content;
			
			return $content;
		}
		
		
	}
