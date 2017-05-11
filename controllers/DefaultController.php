<?php

namespace carono\exchange1c\controllers;

use carono\exchange1c\behaviors\BomBehavior;
use carono\exchange1c\ExchangeEvent;
use carono\exchange1c\ExchangeModule;
use carono\exchange1c\helpers\ByteHelper;
use carono\exchange1c\helpers\NodeHelper;
use carono\exchange1c\helpers\SerializeHelper;
use carono\exchange1c\interfaces\DocumentInterface;
use carono\exchange1c\interfaces\PartnerInterface;
use carono\exchange1c\interfaces\ProductInterface;
use Yii;
use yii\base\Event;
use yii\base\Exception;
use yii\db\ActiveRecord;
use yii\filters\auth\HttpBasicAuth;
use yii\filters\ContentNegotiator;
use yii\helpers\ArrayHelper;
use yii\helpers\FileHelper;
use yii\web\Controller;
use yii\web\Response;
use Zenwalker\CommerceML\CommerceML;
use Zenwalker\CommerceML\Model\Category;
use Zenwalker\CommerceML\Model\Group;
use Zenwalker\CommerceML\Model\Image;
use Zenwalker\CommerceML\Model\Properties;
use Zenwalker\CommerceML\Model\Property;
use Zenwalker\CommerceML\Model\Simple;
use Zenwalker\CommerceML\Model\RequisiteCollection;
use Zenwalker\CommerceML\Model\SpecificationCollection;

/**
 * Default controller for the `exchange` module
 * @property ExchangeModule $module
 */
class DefaultController extends Controller
{
    public $enableCsrfValidation = false;
    const EVENT_BEFORE_UPDATE = 'beforeUpdate';
    const EVENT_AFTER_UPDATE = 'afterUpdate';
    const EVENT_BEFORE_SYNC = 'beforeSync';
    const EVENT_AFTER_SYNC = 'afterSync';
    private $_ids;

    public function init()
    {
        set_time_limit(60 * 60);
        if (!$this->module->productClass) {
            throw new Exception('1');
        }
        $c = new $this->module->productClass;

        if (!$c instanceof ProductInterface) {
            throw new Exception('2');
        }
        parent::init();
    }

    public function actionDownload($file)
    {
        $path = $this->getTmpDir() . '/' . $file;
        if (file_exists($path)) {
            return \Yii::$app->response->sendContentAsFile(file_get_contents($path), $file, []);
        }
        return '';
    }


    public function actionList()
    {
        foreach (glob($this->getTmpDir() . '/*') as $file) {
            $name = basename($file);
            echo "<a href='/exchange/default/download?file=$name'>$name</a><br>";
        }
    }

    public function actionIndex()
    {
        return '';
    }

    public function auth($login, $password)
    {
        /**
         * @var $class \yii\web\IdentityInterface
         */
        $class = Yii::$app->user->identityClass;
        $user = $class::findByUsername($login);
        if ($user && $user->validatePassword($password)) {
            return $user;
        } else {
            return null;
        }
    }

    public function behaviors()
    {
        $behaviors = [
            'bom' => [
                'class' => BomBehavior::className(),
                'only'  => ['query']
            ]
        ];
        if (Yii::$app->user->isGuest) {
            if ($this->module->auth) {
                $auth = $this->module->auth;
            } else {
                $auth = [$this, 'auth'];
            }
            return ArrayHelper::merge(
                $behaviors, [
                    'basicAuth' => [
                        'class'  => HttpBasicAuth::className(),
                        'auth'   => $auth,
                        'except' => ['index']
                    ]
                ]
            );
        }
        return $behaviors;
    }

    public function afterAction($action, $result)
    {
        Yii::$app->response->headers->set('uid', Yii::$app->user->getId());
        if (is_bool($result)) {
            return $result ? "success" : "failure";
        } elseif (is_array($result)) {
            $r = [];
            foreach ($result as $key => $value) {
                $r[] = is_int($key) ? $value : $key . '=' . $value;
            }
            return join("\n", $r);
        } else {
            return parent::afterAction($action, $result);
        }
    }

    public function actionCheckauth($type)
    {
        if (Yii::$app->user->isGuest) {
            return false;
        } else {
            return [
                "success",
                "PHPSESSID",
                Yii::$app->session->getId()
            ];
        }
    }

    protected function getFileLimit()
    {
        $limit = ByteHelper::maximum_upload_size();
        if (!($limit % 2)) {
            $limit--;
        }
        return $limit;
    }

    public function actionInit()
    {
        @unlink(self::getTmpDir() . DIRECTORY_SEPARATOR . 'import.xml');
        @unlink(self::getTmpDir() . DIRECTORY_SEPARATOR . 'offers.xml');
        return [
            "zip"        => class_exists('ZipArchive') && $this->module->useZip ? "yes" : "no",
            "file_limit" => $this->getFileLimit()
        ];
    }

    public function actionFile($type, $filename)
    {
        $body = Yii::$app->request->getRawBody();
        $filePath = self::getTmpDir() . DIRECTORY_SEPARATOR . $filename;
        if (!self::getData('archive') && pathinfo($filePath, PATHINFO_EXTENSION) == 'zip') {
            self::setData('archive', $filePath);
        }
        file_put_contents($filePath, $body, FILE_APPEND);
        if ((int)Yii::$app->request->headers->get('Content-Length') != $this->getFileLimit()) {
            $this->afterFinishUploadFile($filePath);
        }
        return true;
    }

    /**
     * @param $filePath
     */
    public function afterFinishUploadFile($filePath)
    {
        //
    }

    public function beforeSync()
    {
        $event = new ExchangeEvent();
        $this->module->trigger(self::EVENT_BEFORE_SYNC, $event);
    }


    public function afterSync()
    {
        $event = new ExchangeEvent();
        $event->ids = $this->_ids;
        $this->module->trigger(self::EVENT_AFTER_SYNC, $event);
    }

    public function parsing($import, $offers)
    {
        $this->beforeSync();
        $this->_ids = [];
        $commerce = new CommerceML();
        $commerce->addXmls($import, $offers);
        foreach ($commerce->catalog->getProducts() as $product) {
            if (!$model = $this->findModel($product)) {
                $model = $this->createModel();
                $model->save(false);
            }
            $this->parseProduct($model, $product);
            $this->_ids[] = $model->getPrimaryKey();
        }
        $this->afterSync();
    }

    public function actionLoad()
    {
        $import = self::getTmpDir() . DIRECTORY_SEPARATOR . 'import.xml';
        $offers = self::getTmpDir() . DIRECTORY_SEPARATOR . 'offers.xml';
        $this->parsing($import, $offers);
    }

    public function actionImport($type, $filename)
    {
        if ($filename == 'offers.xml') {
            return true;
        }
        if ($archive = self::getData('archive')) {
            $zip = new \ZipArchive();
            $zip->open($archive);
            $zip->extractTo(dirname($archive));
            $zip->close();
        }
        $import = self::getTmpDir() . DIRECTORY_SEPARATOR . 'import.xml';
        $offers = self::getTmpDir() . DIRECTORY_SEPARATOR . 'offers.xml';
        $this->parsing($import, $offers);
        if (!$this->module->debug) {
            $this->clearTmp();
        }
        return true;
    }

    protected function clearTmp()
    {
        if ($archive = self::getData('archive')) {
            @unlink($archive);
        }
        if (is_dir($files = self::getTmpDir() . DIRECTORY_SEPARATOR . 'import_files')) {
            FileHelper::removeDirectory($files);
        }
        if (file_exists($import = self::getTmpDir() . DIRECTORY_SEPARATOR . 'import.xml')) {
            @unlink($import);
        }
        if ($offers = self::getTmpDir() . DIRECTORY_SEPARATOR . 'offers.xml') {
            @unlink($offers);
        }
    }


    public function actionQuery($type)
    {
        /**
         * @var DocumentInterface $document
         */

        /*
                echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
                $xml = new \SimpleXMLElement('<root></root>');
                $root = $xml->addChild('КоммерческаяИнформация');
                $root->addAttribute('ВерсияСхемы', '2.04');
                $root->addAttribute('ДатаФормирования', date('Y-m-d\TH:i:s'));
                return $root->asXML();
        */

        $response = Yii::$app->response;
        $response->format = Response::FORMAT_RAW;
        $response->getHeaders()->set('Content-Type', 'application/xml; charset=windows-1251');

        $root = new \SimpleXMLElement('<КоммерческаяИнформация></КоммерческаяИнформация>');

        $root->addAttribute('ВерсияСхемы', '2.04');
        $root->addAttribute('ДатаФормирования', date('Y-m-d\TH:i:s'));

        $document = $this->module->documentClass;

        foreach ($document::findOrders1c() as $order) {
            NodeHelper::appendNode($root, SerializeHelper::serializeDocument($order));
        }

        if ($this->module->debug) {
            $xml = $root->asXML();
            $xml = html_entity_decode($xml, ENT_NOQUOTES, 'UTF-8');
            file_put_contents($this->getTmpDir() . '/query.xml', $xml);
        }
        return $root->asXML();
    }

    public function actionSuccess($type)
    {
        return true;
    }

    protected static function setData($name, $value)
    {
        Yii::$app->session->set($name, $value);
    }

    protected static function getData($name, $default = null)
    {
        return Yii::$app->session->get($name, $default);
    }

    protected static function clearData()
    {
        return Yii::$app->session->closeSession();
    }

    protected function getTmpDir()
    {
        $dir = Yii::getAlias($this->module->tmpDir);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        return $dir;
    }

    /**
     * @param                                     $model ProductInterface
     * @param \Zenwalker\CommerceML\Model\Product $product
     */
    protected function parseProduct($model, $product)
    {
        /**
         * @var Simple $value
         */
        $fields = $model::getFields1c();
        $this->beforeUpdate($model);
        $this->parseGroups($model, $product->getGroup());
        $this->parseRequisites($model, $product->getRequisites());
        $this->parseSpecifications($model, $product->getSpecifications());
        $this->parseProperties($model, $product->getProperties());
        foreach ($fields as $accountingField => $modelField) {
            if ($modelField) {
                $model->{$modelField} = (string)$product->{$accountingField};
            }
        }
        $this->parsePrice($model, $product->getPrices());
        $this->parseImage($model, $product->getImages());
        $model->save();
        $this->afterUpdate($model);
    }

    public function afterUpdate($model)
    {
        $event = new ExchangeEvent();
        $event->model = $model;
        $this->module->trigger(self::EVENT_AFTER_UPDATE, $event);
    }

    public function beforeUpdate($model)
    {
        $event = new ExchangeEvent();
        $event->model = $model;
        $this->module->trigger(self::EVENT_BEFORE_UPDATE, $event);
    }

    /**
     * @param \Zenwalker\CommerceML\Model\Product $product
     *
     * @return ActiveRecord|null
     */
    protected function findModel($product)
    {
        /**
         * @var $class ProductInterface
         */
        $class = $this->getProductClass();
        $id = $class::getFields1c()['id'];
        return $class::find()->andWhere([$id => $product->id])->one();
    }

    protected function createModel()
    {
        /**
         * @var $class ProductInterface
         */
        $class = $this->getProductClass();
        $model = new $class;
        return $model;
    }

    /**
     * @param ProductInterface $model
     * @param \Zenwalker\CommerceML\Model\Price[] $prices
     */
    protected function parsePrice($model, $prices)
    {
        foreach ($prices as $price) {
            $model->setPrice1c($price);
        }
    }

    /**
     * @param ProductInterface $model
     * @param Image[] $images
     */
    protected function parseImage($model, $images)
    {
        foreach ($images as $image) {
            $path = realpath($this->getTmpDir() . DIRECTORY_SEPARATOR . $image->path);
            if (file_exists($path)) {
                $model->addImage1c($path, $image->caption);
            }
        }
    }

    /**
     * @param ProductInterface $model
     * @param Group $group
     */
    protected function parseGroups($model, $group)
    {
        $model->setGroup1c($group);
    }

    /**
     * @param ProductInterface $model
     * @param RequisiteCollection $requisites
     */
    protected function parseRequisites($model, $requisites)
    {
        foreach ($requisites as $requisite) {
            $model->setRequisite1c($requisite->name, $requisite->value);
        }
    }

    /**
     * @param ProductInterface $model
     * @param SpecificationCollection $specifications
     */
    protected function parseSpecifications($model, $specifications)
    {
        foreach ($specifications as $specification) {
            $model->setSpecification1c($specification->name, $specification->value);
        }
    }

    /**
     * @param ProductInterface $model
     * @param Properties $properties
     */
    protected function parseProperties($model, $properties)
    {
        foreach ($properties as $property) {
            $model->setProperty1c($property);
        }
    }

    /**
     * @return ActiveRecord
     */
    protected function getProductClass()
    {
        return $this->module->productClass;
    }

    /**
     * @return DocumentInterface
     */
    protected function getDocumentClass()
    {
        return $this->module->documentClass;
    }
}
