<?php
/**
 * Model.php
 * @author Revin Roman
 * @link https://rmrevin.com
 */

namespace cookyii\modules\Postman\resources\PostmanMessage;

use cookyii\db\traits\SoftDeleteTrait;
use cookyii\Facade as F;
use cookyii\helpers\Premailer;
use cookyii\modules\Postman\jobs\SendMailJob;
use cookyii\modules\Postman\resources\PostmanMessageAttach\Model as PostmanMessageAttachModel;
use cookyii\modules\Postman\resources\PostmanTemplate\Model as PostmanTemplateModel;
use cookyii\traits\PopulateErrorsTrait;
use yii\helpers\Html;
use yii\helpers\Json;
use yii\validators\EmailValidator;

/**
 * Class Model
 * @package cookyii\modules\Postman\resources\PostmanMessage
 *
 * @property integer $id
 * @property string $subject
 * @property string $content_text
 * @property string $content_html
 * @property string $address
 * @property integer $try_message_id
 * @property string $code
 * @property string $error
 * @property integer $created_at
 * @property integer $scheduled_at
 * @property integer $executed_at
 * @property integer $sent_at
 * @property integer $deleted_at
 *
 * @property PostmanMessageAttachModel[] $messageAttachments
 */
class Model extends \cookyii\db\ActiveRecord
{

    use Serialize,
        SoftDeleteTrait,
        PopulateErrorsTrait;

    const LAYOUT_CODE = '.layout';

    static $tableName = '{{%postman_message}}';

    /**
     * @var string ID of postman component
     */
    public static $postman = 'postman';

    /**
     * @var string ID of view component
     */
    public static $view = 'view';

    /**
     * @var string ID of url manager component
     */
    public static $urlManager = 'urlManager';

    /**
     * @inheritdoc
     */
    public function behaviors()
    {
        $behaviors = parent::behaviors();

        $behaviors['unique-code-id'] = [
            'class' => \cookyii\behaviors\UniqueCodeIdBehavior::class,
            'codeAtAttribute' => 'code',
            'length' => 32,
        ];

        $behaviors['timestamp'] = [
            'class' => \cookyii\behaviors\TimestampBehavior::class,
            'updatedAtAttribute' => false,
        ];

        return $behaviors;
    }

    /**
     * @inheritdoc
     */
    public function rules()
    {
        return [
            /** type validators */
            [['subject', 'content_text', 'content_html', 'address', 'code', 'error'], 'string'],
            [['try_message_id', 'created_at', 'scheduled_at', 'executed_at', 'sent_at', 'deleted_at'], 'integer'],

            /** semantic validators */
            [['subject'], 'required'],
            [['subject'], 'filter', 'filter' => 'str_clean'],
            [['content_text', 'content_html', 'error'], 'filter', 'filter' => 'str_pretty'],

            /** default values */
        ];
    }

    /**
     * @return bool
     */
    public function isSent()
    {
        return !empty($this->sent_at);
    }

    /**
     * @return array
     */
    public function expandAddress()
    {
        return Json::decode($this->address);
    }

    /**
     * @return null|\cookyii\modules\Postman\Module
     */
    public static function getPostman()
    {
        return \Yii::$app->getModule(static::$postman);
    }

    /**
     * @param integer $type
     * @param string $email
     * @param string|null $name
     * @return static
     */
    protected function addAddress($type, $email, $name = null)
    {
        $address = empty($this->address) ? [] : Json::decode($this->address);

        $address[] = [
            'type' => $type,
            'email' => $email,
            'name' => $name,
        ];

        $this->address = Json::encode($address);

        return $this;
    }

    /**
     * @param string $email
     * @param string|null $name
     * @return static
     */
    public function addReplyTo($email, $name = null)
    {
        return $this->addAddress(static::ADDRESS_TYPE_REPLY_TO, $email, $name);
    }

    /**
     * @param string $email
     * @param string|null $name
     * @return static
     */
    public function addTo($email, $name = null)
    {
        return $this->addAddress(static::ADDRESS_TYPE_TO, $email, $name);
    }

    /**
     * @param string $email
     * @param string|null $name
     * @return static
     */
    public function addCc($email, $name = null)
    {
        return $this->addAddress(static::ADDRESS_TYPE_CC, $email, $name);
    }

    /**
     * @param string $email
     * @param string|null $name
     * @return static
     */
    public function addBcc($email, $name = null)
    {
        return $this->addAddress(static::ADDRESS_TYPE_BCC, $email, $name);
    }

    /**
     * @param integer $try
     * @param string $period strtotime string format
     * @return boolean
     * @throws \yii\base\InvalidConfigException
     */
    public function repeatAfter($try, $period)
    {
        $try_message_id = !empty($this->try_message_id) ? $this->try_message_id : $this->id;

        $count = static::find()
            ->byTryMessageId($try_message_id)
            ->count();

        if ($count >= $try) {
            $this->addError('try_message_id', \Yii::t('cookyii.postman', 'Limit exceeded retries'));
        } else {
            $Message = new static;
            $Message->setAttributes([
                'subject' => $this->subject,
                'content_text' => $this->content_text,
                'content_html' => $this->content_html,
                'address' => $this->address,
                'try_message_id' => !empty($this->try_message_id) ? $this->try_message_id : $this->id,
                'scheduled_at' => strtotime($period),
            ]);

            $Message->validate() && $Message->save();

            if (!$Message->hasErrors()) {
                /** @var SendMailJob $Job */
                $Job = \Yii::createObject([
                    'class' => SendMailJob::class,
                    'postmanMessageId' => $Message->id,
                ]);

                F::Queue()->push($Job);
            } else {
                $this->populateErrors($Message, 'id');
            }
        }

        return !$this->hasErrors();
    }

    /**
     * @return string
     * @throws \yii\base\InvalidParamException
     */
    public function getWebVersionUrl()
    {
        $code = $this->code;

        $hash = F::Security()->encryptByPassword(Json::encode([
            't' => time(),
            'c' => $code,
            'u' => uniqid(),
        ]), COOKIE_VALIDATION_KEY);

        $hash = base64_encode($hash);

        return F::UrlManager('frontend')->createAbsoluteUrl([
            '/postman/letter/show',
            'token' => $code,
            'hash' => $hash,
        ]);
    }

    /**
     * Put message to queue
     * @return bool
     */
    public function sendToQueue()
    {
        $result = $this->validate() && $this->save();

        if (!$this->isNewRecord) {
            /** @var SendMailJob $Job */
            $Job = \Yii::createObject([
                'class' => SendMailJob::class,
                'postmanMessageId' => $this->id,
            ]);

            F::Queue()->push($Job);
        }

        return $result;
    }

    /**
     * Send message to user immediately
     * @deprecated
     * @return bool|array
     * @throws \Exception
     */
    public function send()
    {
        return $this->sendImmediately();
    }

    /**
     * Send message to user immediately
     * @return bool
     * @throws \Exception
     */
    public function sendImmediately()
    {
        $result = false;

        $Mailer = F::Mailer();

        $this->executed_at = time();

        $this->validate() && $this->save();

        $Postman = static::getPostman();

        if ($this->hasErrors()) {
            return $result;
        }

        $address = empty($this->address)
            ? []
            : Json::decode($this->address);

        $reply_to = [];
        $to = [];
        $cc = [];
        $bcc = [];

        if (empty($address)) {
            $this->addError('address', \Yii::t('cookyii.postman', 'Cannot send message without a recipient'));

            return $result;
        }

        foreach ($address as $addr) {
            switch ($addr['type']) {
                case static::ADDRESS_TYPE_REPLY_TO:
                    $reply_to[$addr['email']] = $addr['name'];
                    break;
                case static::ADDRESS_TYPE_TO:
                    $to[$addr['email']] = $addr['name'];
                    break;
                case static::ADDRESS_TYPE_CC:
                    $cc[$addr['email']] = $addr['name'];
                    break;
                case static::ADDRESS_TYPE_BCC:
                    $bcc[$addr['email']] = $addr['name'];
                    break;
            }
        }

        $from = empty($Postman->from)
            ? [SMTP_USER => 'Postman']
            : $Postman->from;

        $Validator = new EmailValidator;

        if (is_string($from) && !$Validator->validate($from)) {
            $from = [SMTP_USER => 'Postman'];
        }

        $web_version = $this->getWebVersionUrl();

        $Message = \Yii::$app->mailer->compose()
            ->setCharset('UTF-8')
            ->setFrom($from)
            ->setSubject($this->subject);

        $content_text = str_replace('#web_version#', $web_version, $this->content_text);
        $content_html = str_replace('#web_version#', $web_version, $this->content_html);

        $embed = [];

        preg_match_all('/(\s*data:([a-z]+\/[a-z0-9-+.]+(;[a-z-]+=[a-z0-9-]+)?)?(;base64)?,([^)"\']*)\s*)/i', $content_html, $matches);

        if (!empty($matches)) {
            foreach ($matches[0] as $key => $match) {
                $hash = md5($match);

                if (!isset($embed[$hash])) {
                    $embed[$hash] = $Message->embedContent(file_get_contents($match), [
                        'contentType' => $matches[2][$key],
                    ]);

                    $content_html = str_replace($match, $embed[$hash], $content_html);
                }
            }
        }

        $embed = [];

        $attachments = $this->messageAttachments;

        foreach ($attachments as $attachment) {
            $media = $attachment->media;
            if (null === $media) {
                continue;
            }

            if ($attachment->embed === null) {
                $Message->attach($media->getAbsolutePath(), [
                    'fileName' => $media->origin_name,
                ]);
            } else {
                $embed[$attachment->embed] = $Message->embed($media->getAbsolutePath(), [
                    'fileName' => $media->origin_name,
                ]);

                $content_html = str_replace([
                    '#embed[' . $attachment->embed . ']#',
                    '#embed%5B' . $attachment->embed . '%5D#',
                ], $embed[$attachment->embed], $content_html);
            }
        }

        $Message
            ->setTextBody($content_text)
            ->setHtmlBody($content_html);

        if (!empty($reply_to)) {
            $Message->setReplyTo($reply_to);
        }

        if (!empty($to)) {
            $Message->setTo($to);
        }

        if (!empty($cc)) {
            $Message->setCc($cc);
        }

        if (!empty($bcc)) {
            $Message->setBcc($bcc);
        }

        $result = $Message->send($Mailer);

        if ($Mailer instanceof \yii\swiftmailer\Mailer) {
            $Mailer->getTransport()->stop();
        }

        if ($result === true) {
            $this->sent_at = time();
            $this->update();
        } else {
            $this->addError('sent_at', \Yii::t('cookyii.postman', 'Failed to send message'));
        }

        return $result;
    }

    /**
     * Create message from template
     * @param string $template_code
     * @param array $placeholders
     * @param string|null $subject
     * @return static
     * @throws \yii\web\ServerErrorHttpException
     */
    public static function create($template_code, $placeholders = [], $subject = null)
    {
        /** @var PostmanTemplateModel $TemplateModel */
        $TemplateModel = \Yii::createObject(PostmanTemplateModel::class);

        $Template = $TemplateModel::find()
            ->byCode($template_code)
            ->one();

        if (empty($Template)) {
            throw new \yii\web\ServerErrorHttpException(sprintf('Message template `%s` not found.', $template_code));
        }

        $subject = empty($subject)
            ? $Template->subject
            : $subject;

        $Message = static::compose(
            $subject,
            $Template->content_text,
            $Template->content_html,
            $placeholders,
            $Template->styles,
            $Template->use_layout
        );

        $Message->address = $Template->address;

        return $Message;
    }

    /**
     * Compose message from string values
     * @param string $subject
     * @param string $content_text
     * @param string $content_html
     * @param array $placeholders
     * @param string $styles
     * @param bool $use_layout
     * @return static
     */
    public static function compose(
        $subject,
        $content_text,
        $content_html,
        $placeholders = [],
        $styles = '',
        $use_layout = true
    ) {
        if (!$use_layout) {
            $layout_text = '{content}';
            $layout_html = '{content}';
        } else {
            list($layout_text, $layout_html, $css) = static::getLayout();
            $styles .= $css;
        }

        $Postman = static::getPostman();

        $Message = new static;
        $Message->subject = trim(sprintf('%s %s %s', $Postman->subjectPrefix, $subject, $Postman->subjectSuffix));

        $host = \Yii::$app->get(static::$urlManager)->hostInfo;

        $base_placeholders = [
            '{host}' => $host,
            '{domain}' => parse_url($host, PHP_URL_HOST),
            '{appname}' => APP_NAME,
            '{subject}' => $subject,
            '{user_id}' => null,
            '{username}' => null,
        ];

        if (F::Request() instanceof \yii\web\Request) {
            $Account = F::Account();

            if ($Account instanceof \cookyii\interfaces\AccountInterface) {
                $base_placeholders['{user_id}'] = $Account->getId();
                $base_placeholders['{username}'] = $Account->getName();
            }
        }

        $placeholders = array_merge([], $base_placeholders, $placeholders);

        $Message->content_text = str_replace('{content}', $content_text, $layout_text);
        $Message->content_text = str_replace(
            array_keys($placeholders),
            array_values($placeholders),
            $Message->content_text
        );

        $Message->content_html = str_replace('{content}', $content_html, $layout_html);
        $Message->content_html = str_replace(
            array_keys($placeholders),
            array_values($placeholders),
            $Message->content_html
        );

        $styles = empty($styles)
            ? null
            : Html::tag('style', $styles, ['type' => 'text/css']);

        if (!empty($styles)) {
            if (preg_match('|<body[^\>]*>|i', $Message->content_html, $m)) {
                $Message->content_html = str_replace($m[0], $m[0] . PHP_EOL . $styles, $Message->content_html);
            } else {
                $Message->content_html = $styles . $Message->content_html;
            }
        }

        if ($Postman->usePremailer) {
            $Message->content_html = Premailer::getConvertedHtml($Message->content_html);
        }

        return $Message;
    }

    /**
     * @param string|boolean $layout
     * @return array
     * @throws \yii\base\InvalidConfigException
     */
    public static function getLayout($layout = false)
    {
        $result = [
            'text' => '{content}',
            'html' => '{content}',
            'css' => null,
        ];

        $Postman = static::getPostman();

        $layout = empty($layout)
            ? $Postman->defaultLayout
            : $layout;

        $layoutVariants = $Postman->layoutVariants;

        if (isset($layoutVariants[$layout])) {
            $construct = $layoutVariants[$layout];

            if (is_callable($construct)) {
                $result = $construct();
            } elseif (is_array($construct)) {
                $result = [
                    'text' => empty($construct['text']) ? '{content}' : static::renderView(\Yii::getAlias($construct['text'])),
                    'html' => empty($construct['html']) ? '{content}' : static::renderView(\Yii::getAlias($construct['html'])),
                    'css' => empty($construct['css']) ? '' : static::renderView(\Yii::getAlias($construct['css'])),
                ];
            } else {
                throw new \yii\base\InvalidConfigException;
            }
        } elseif ($layout === 'database') {
            /** @var PostmanTemplateModel $TemplateModel */
            $TemplateModel = \Yii::createObject(PostmanTemplateModel::class);

            $LayoutTemplate = $TemplateModel::find()
                ->byCode(static::LAYOUT_CODE)
                ->one();

            if (!empty($LayoutTemplate)) {
                $result = [
                    'text' => $LayoutTemplate->content_text,
                    'html' => $LayoutTemplate->content_html,
                    'css' => $LayoutTemplate->styles,
                ];
            }
        }

        return [$result['text'], $result['html'], $result['css']];
    }

    /**
     * @param string $viewFile
     * @param array $params
     * @return string
     */
    public static function renderView($viewFile, $params = [])
    {
        $params['content'] = '{content}';

        return \Yii::$app->get(static::$view)->renderFile($viewFile, $params);
    }

    /**
     * @return \cookyii\modules\Postman\resources\PostmanMessageAttach\Query
     */
    public function getMessageAttachments()
    {
        return $this->hasMany(PostmanMessageAttachModel::class, ['message_id' => 'id'])
            ->inverseOf('message');
    }

    /**
     * @return Query
     */
    public static function find()
    {
        return \Yii::createObject(Query::class, [get_called_class()]);
    }

    const ADDRESS_TYPE_REPLY_TO = 1;
    const ADDRESS_TYPE_TO = 2;
    const ADDRESS_TYPE_CC = 3;
    const ADDRESS_TYPE_BCC = 4;
}
