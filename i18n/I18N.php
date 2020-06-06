<?php
	/**
	 * @link      http://www.yiiframework.com/
	 * @copyright Copyright (c) 2008 Yii Software LLC
	 * @license   http://www.yiiframework.com/license/
	 */
	
	namespace system\lib\i18n;
	
	use Yii;
	use yii\base\Component;
	use yii\base\InvalidConfigException;
	use function is_array;
	use function realpath;
	use function unlink;
	
	/**
	 * I18N provides features related with internationalization (I18N) and localization (L10N).
	 *
	 * I18N is configured as an application component in [[\yii\base\Application]] by default.
	 * You can access that instance via `Yii::$app->i18n`.
	 *
	 * @property MessageFormatter $messageFormatter The message formatter to be used to format message via ICU
	 * message format. Note that the type of this property differs in getter and setter. See
	 * [[getMessageFormatter()]] and [[setMessageFormatter()]] for details.
	 *
	 * @author Qiang Xue <qiang.xue@gmail.com>
	 * @since  2.0
	 */
	class I18N extends Component {
		/**
		 * @var array list of [[MessageSource]] configurations or objects. The array keys are message
		 * category patterns, and the array values are the corresponding [[MessageSource]] objects or the
		 * configurations
		 * for creating the [[MessageSource]] objects.
		 *
		 * The message category patterns can contain the wildcard `*` at the end to match multiple categories with the
		 * same prefix. For example, `app/*` matches both `app/cat1` and `app/cat2`.
		 *
		 * The `*` category pattern will match all categories that do not match any other category patterns.
		 *
		 * This property may be modified on the fly by extensions who want to have their own message sources
		 * registered under their own namespaces.
		 *
		 * The category `yii` and `app` are always defined. The former refers to the messages used in the Yii core
		 * framework code, while the latter refers to the default message category for custom application code.
		 * By default, both of these categories use [[PhpMessageSource]] and the corresponding message files are
		 * stored under `@yii/messages` and `@app/messages`, respectively.
		 *
		 * You may override the configuration of both categories.
		 */
		public $translations;
		
		public static $files=[];
		/**
		 * Initializes the component by configuring the default message categories.
		 */
		public function init() {
			parent::init();

			if ( ! isset( $this->translations['yii'] ) && ! isset( $this->translations['yii*'] ) ) {
				$this->translations['yii'] = [
					'class'          => 'system\lib\i18n\PhpMessageSource' ,
					'sourceLanguage' => 'en-US' ,
					'basePath'       => '@yii/messages' ,
				];
			}
			
			if ( ! isset( $this->translations['app'] ) && ! isset( $this->translations['app*'] ) ) {
				$this->translations['app'] = [
					'class'          => 'system\lib\i18n\PhpMessageSource' ,
					'sourceLanguage' => Yii::$app->sourceLanguage ,
					'basePath'       => '@app/messages' ,
				];
			}
		}
		
		/**
		 * Translates a message to the specified language.
		 *
		 * After translation the message will be formatted using [[MessageFormatter]] if it contains
		 * ICU message format and `$params` are not empty.
		 *
		 * @param string $category the message category.
		 * @param string $message  the message to be translated.
		 * @param array  $params   the parameters that will be used to replace the corresponding placeholders in the
		 *                         message.
		 * @param string $language the language code (e.g. `en-US`, `en`).
		 *
		 * @return string the translated and formatted message.
		 */
		public function translate( $category , $message , $params , $language ) {
			$messageSource = $this->getMessageSource( $category );

			$translation   = $messageSource->translate( $category , $message , $language );

			if ( $translation === false ) {
				$this->addKeyToTranslationFile( $category , $message );
				
				return $this->format( $message , $params , $messageSource->sourceLanguage );
			}

			return $this->format( $translation , $params , $language );
		}
		
		/**
		 * Formats a message using [[MessageFormatter]].
		 *
		 * @param string $message  the message to be formatted.
		 * @param array  $params   the parameters that will be used to replace the corresponding placeholders in the
		 *                         message.
		 * @param string $language the language code (e.g. `en-US`, `en`).
		 *
		 * @return string the formatted message.
		 */
		public function format( $message , $params , $language ) {
			$params = (array) $params;
			if ( $params === [] ) {
				return $message;
			}
			
			if ( preg_match( '~{\s*[\w.]+\s*,~u' , $message ) ) {
				$formatter = $this->getMessageFormatter();
				$result    = $formatter->format( $message , $params , $language );
				if ( $result === false ) {
					$errorMessage = $formatter->getErrorMessage();
					Yii::warning(
						"Formatting message for language '$language' failed with error: $errorMessage. The message being formatted was: $message." ,
						__METHOD__
					);
					
					return $message;
				}
				
				return $result;
			}
			
			$p = [];
			foreach ( $params as $name => $value ) {
				$p[ '{' . $name . '}' ] = $value;
			}
			
			return strtr( $message , $p );
		}
		
		/**
		 * @var string|array|MessageFormatter
		 */
		private $_messageFormatter;
		
		/**
		 * Returns the message formatter instance.
		 * @return MessageFormatter the message formatter to be used to format message via ICU message format.
		 */
		public function getMessageFormatter() {
			if ( $this->_messageFormatter === null ) {
				$this->_messageFormatter = new MessageFormatter();
			} else if ( is_array( $this->_messageFormatter ) || is_string( $this->_messageFormatter ) ) {
				$this->_messageFormatter = Yii::createObject( $this->_messageFormatter );
			}
			
			return $this->_messageFormatter;
		}
		
		/**
		 * @param string|array|MessageFormatter $value the message formatter to be used to format message via ICU
		 *                                             message format. Can be given as array or string configuration
		 *                                             that will be given to [[Yii::createObject]] to create an
		 *                                             instance or a [[MessageFormatter]] instance.
		 */
		public function setMessageFormatter( $value ) {
			$this->_messageFormatter = $value;
		}
		
		/**
		 * Returns the message source for the given category.
		 *
		 * @param string $category the category name.
		 *
		 * @return MessageSource the message source for the given category.
		 * @throws InvalidConfigException if there is no message source available for the specified category.
		 */
		public function getMessageSource( $category ) {
			$this->AttachIfCreatedFile( $category );
			if ( isset( $this->translations[ $category ] ) ) {
				$source = $this->translations[ $category ];
				if ( $source instanceof MessageSource || $source instanceof \yii\i18n\MessageSource ) {
					return $source;
				}
				
				return $this->translations[ $category ] = Yii::createObject( $source );
				
			}
			
			
			// try wildcard matching
			foreach ( $this->translations as $pattern => $source ) {
				if ( strpos( $pattern , '*' ) > 0 && strpos( $category , rtrim( $pattern , '*' ) ) === 0 ) {
					if ( $source instanceof MessageSource || $source instanceof \yii\i18n\MessageSource ) {
						return $source;
					}
					
					return $this->translations[ $category ] = $this->translations[ $pattern ] = Yii::createObject(
						$source
					);
					
				}
			}
			
			// match '*' in the last
			if ( isset( $this->translations['*'] ) ) {
				$source = $this->translations['*'];
				if ( $source instanceof MessageSource || $source instanceof \yii\i18n\MessageSource ) {
					return $source;
				}
				
				return $this->translations[ $category ] = $this->translations['*'] = Yii::createObject( $source );
			}
			$this->createTranslationCategory( $category );
			
			return $this->getMessageSource( $category );
			throw new InvalidConfigException( "Unable to locate message source for category '$category'." );
		}
		
		public function AttachIfCreatedFile( $category ) {
			$systemDir = realpath( Yii::getAlias( '@system' ) );
			$lang      = Yii::$app->language;
			$isModule  = realpath( $systemDir . '/modules/' . $category );
			if ( $isModule ) {
				$fullDir  = $systemDir . '/modules/' . $category . '/translates/' . $lang . '/' . $category . '.php';
				$basePath = $systemDir . '/modules/' . $category . '/translates/';
			} else {
				$fullDir  = $systemDir . '/translates/' . $lang . '/' . $category . '.php';
				$basePath = '@system/translates/';
			}
			$exist = realpath( $fullDir );
			if ( $exist ) {
				$this->translations[ $category ] =
					[
						'class'            => 'system\lib\i18n\PhpMessageSource' ,
						'sourceLanguage'   => Yii::$app->language ,
						'basePath'         => $basePath ,
						'forceTranslation' => true
					];
			}
		}
		
		public function createTranslationCategory( $category ) {
			$systemDir=realpath( Yii::getAlias( '@system' ) );
			$isModule  = realpath( $systemDir . '/modules/' . $category );
			if ($isModule){
				$translateDir=$systemDir .'/modules/'.$category. '/translates/' ;
				@mkdir( $translateDir, 0777 , true );
			}else{
				$translateDir=$systemDir . '/translates/' ;
				@mkdir( $translateDir, 0777 , true );
			}
			
			$list = $this->languageList();
			foreach ( $list as $languageCode => $label ) {
				@mkdir(
					$translateDir . $languageCode ,
					0777 ,
					true
				);
				$phpTranslateFile = realpath(
					$translateDir . $languageCode . '/' . $category . '.php'
				);
				if ( ! $phpTranslateFile ) {
					$file = fopen(
						$translateDir . $languageCode . '/' . $category . '.php' ,
						'w+'
					);
					fwrite( $file , $this->generatePHPFile_array( [ 'start' => 'start' ] ) );
					fclose( $file );
				}
			}
		}
		
		public function generatePHPFile_array( $array ) {
			$json = "<?php \n return \n" . json_encode( $array , JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
			
			return str_replace( [ '":' , '{' , '}' ] , [ '"=>' , '[' , ']' ] , $json ) . ";";
			
		}
		
		public function languageList() {
			return json_decode(
				file_get_contents( realpath( Yii::getAlias( '@system' ) ) . '/lib/i18n/languages.json' )
			);
		}
		
		public function addKeyToTranslationFile( $category , $message ) {
			$list = $this->languageList();
			$systemDir=realpath( Yii::getAlias( '@system' ) );
			$isModule  = realpath( $systemDir . '/modules/' . $category );
			if ($isModule){
				$translateDir=$systemDir .'/modules/'.$category. '/translates/' ;
				@mkdir( $translateDir, 0777 , true );
			}else{
				$translateDir=$systemDir . '/translates/' ;
				@mkdir( $translateDir, 0777 , true );
			}
			
			foreach ( $list as $languageCode => $label ) {
				@mkdir(
					$translateDir . $languageCode ,
					0777 ,
					true
				);
				$phpTranslateFile = realpath(
					$translateDir . $languageCode . '/' . $category . '.php'
				);
				
				if ( ! $phpTranslateFile ) {
					$file = fopen(
						$translateDir . $languageCode . '/' . $category . '.php' ,
						'w'
					);
					fwrite( $file , $this->generatePHPFile_array( [ 'start' => 'start' ] ) );
					fclose( $file );
				} else {
                    $fileId=str_replace(['/','\\','.','-','_'],'',$phpTranslateFile);
                    if (empty(self::$files[$fileId])){
                        self::$files[$fileId] = include $phpTranslateFile;
                    }
					if ( is_array( self::$files[$fileId] ) ) {
						if ( ! isset( self::$files[$fileId][ $message ] ) ) {
                            self::$files[$fileId][ $message ] = $message;
							$file                = fopen(
								$phpTranslateFile ,
								'r+'
							);
							if ( flock( $file , LOCK_EX ) ) {
								ftruncate( $file , 0 );
								
								fwrite( $file , $this->generatePHPFile_array( self::$files[$fileId] ) );
								fflush( $file );
								flock( $file , LOCK_UN ); // unlock the file
								fclose( $file );
							}
						}
					}
				}
			}
		}
	}
