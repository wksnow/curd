<?php


namespace curd\command;


use Symfony\Component\VarExporter\VarExporter;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use think\Exception;
use think\exception\ErrorException;
use think\facade\Db;
use think\validate\ValidateRule;

class Curd extends Command
{
    protected $stubList = [];

    protected function configure()
    {
        $this
            ->setName('curd')
            ->addOption('table', 't', Option::VALUE_REQUIRED, 'table name', null)
            ->addOption('model', 'm', Option::VALUE_OPTIONAL, 'model name', null)
            ->addOption('controller', 'c', Option::VALUE_OPTIONAL, 'controller name', null)
            ->addOption('validate',null,Option::VALUE_OPTIONAL,'validate name',null)
            ->addOption('createTime', 'ct', Option::VALUE_OPTIONAL, 'createTime', "create_time")
            ->addOption('primaryKey', 'pk', Option::VALUE_OPTIONAL, 'primaryKey', "id")
            ->addOption('updateTime', 'ut', Option::VALUE_OPTIONAL, 'updateTime', "update_time")
            ->addOption('is_delete', 'dt', Option::VALUE_OPTIONAL, 'is_delete', "is_delete")
            ->setDescription('Build CRUD controller and model from table');
    }

    protected function execute(Input $input, Output $output)
    {
        $table = $input->getOption("table");//表名

        $model = $input->getOption('model'); //模型名称
//        $model = empty($model)?$table:$model;

        $controller = $input->getOption("controller");//控制器名称
//        $controller = empty($controller)?$table:$controller;

        $validate = $input->getOption('validate'); //验证类
//        $validate = empty($validate)?$table:$validate;

        try {
            //模型不为空
            if(!empty($model)){
                list($modelNamespace, $modelName, $modelFile, $modelArr) = $this->getModelData($model,"model");
                $data=[
                    "createTime"=>$input->getOption('createTime'),
                    "updateTime"=>$input->getOption('updateTime'),
                    "is_delete"=>$input->getOption('is_delete'),
                    "primaryKey"=>$input->getOption('primaryKey'),
                    "modelName"=>$modelName,
                    "modelNamespace"=>$modelNamespace
                ];

                // 生成模型文件
               // $this->writeToFile('model', $data, $modelFile);
                $this->setModelData('model', $data, $modelFile);
            }

            //验证类不为空
            if(!empty($validate)){
                $table_name = config('database.connections.mysql.prefix').$table;
//                $dbconnect = Db::connect("mysql");
                $sql1 ="SHOW FULL COLUMNS FROM {$table_name}";
//                $res1 = $dbconnect->query($sql1);
                $res1 = Db::query($sql1);
                $fields=$attributes=[];//字段内容
                foreach ($res1 as $k=>$v){
                    $fields[$v['Field']]=[];
                    $attributes[$v['Field']]=$v['Comment'];
                }

                list($validateNamespace, $validateName, $validateFile, $validateArr) = $this->getModelData($validate, "validate");
                $data=[
                    "validateName"=>$validateName,
                    "validateNamespace"=>$validateNamespace,
                    'attributes'=>$attributes,
                    'rule'=>$fields
                ];

                //判断存不存在父类文件
                $extendfile = \think\facade\App::getRootPath()."extend/helper/ExtendValidate.php";
                if(!file_exists($extendfile)){
                    $this->writeToFile('ExtendValidate',[],$extendfile);
                }
                // 生成验证的文件
                $this->setArrayData('validate', $data, $validateFile);
            }

        } catch (\Exception $e) {
            throw new Exception("Code: " . $e->getCode() . "\nLine: " . $e->getLine() . "\nMessage: " . $e->getMessage() . "\nFile: " . $e->getFile());
        }
        $output->info("Build Successed");
    }

    protected function setModelData($name, $data,$pathname)
    {
        $stubcontent = file_get_contents($this->getStub($name));

        $stubcontent = str_replace('{%modelNamespace%}', $data['modelNamespace'], $stubcontent);
        $stubcontent = str_replace('{%modelName%}', $data['modelName'], $stubcontent);
        $stubcontent = str_replace('{%is_delete%}', $data['is_delete'], $stubcontent);
        $stubcontent = str_replace('{%createTime%}', $data['createTime'], $stubcontent);
        $stubcontent = str_replace('{%updateTime%}', $data['updateTime'], $stubcontent);
        $stubcontent = str_replace('{%primaryKey%}', $data['primaryKey'], $stubcontent);
        if (!is_dir(dirname($pathname))) {
            mkdir(dirname($pathname), 0755, true);
        }
        return file_put_contents($pathname, $stubcontent);
    }

    /**
     * 修改验证类的
     * @param $name
     * @param $data
     * @param $pathname
     * @return false|int
     * @throws \Symfony\Component\VarExporter\Exception\ExceptionInterface
     */
    protected function setArrayData($name, $data,$pathname)
    {
        $stubcontent = file_get_contents($this->getStub($name));

        $rule  = VarExporter::export($data['rule']);
        $attributes = VarExporter::export($data['attributes']);

        $stubcontent = str_replace('{%validateNamespace%}', $data['validateNamespace'], $stubcontent);
        $stubcontent = str_replace('{%validateName%}', $data['validateName'], $stubcontent);
        $stubcontent = str_replace('{%rule%}', $rule, $stubcontent);
        $stubcontent = str_replace('{%attributes%}', $attributes, $stubcontent);
        if (!is_dir(dirname($pathname))) {
            mkdir(dirname($pathname), 0755, true);
        }
        return file_put_contents($pathname, $stubcontent);
    }

    /**
     * 分解名称
     * @param $name
     */
    protected function getModelName($name,$method)
    {
        if (strpos($name, '@')) {
            [$app, $name] = explode('@', $name);
        } else {
            $app = 'common';
        }

        $modelName =

        $namespace = $this->getNamespace($app,$method) . '\\' . $name;
        return $namespace;
    }

    protected function getNamespace($app,$method)
    {
        return ($app ? '\\' . $app: '').'\\'.$method ;
    }

    /**
     * 获取模型相关信息
     * @param $module
     * @param $model
     * @param $table
     * @return array
     */
    protected function getModelData($model,$type)
    {
        return $this->getParseNameData($model, $type);
    }

    /**
     * 获取已解析相关信息
     * @param string $module 模块名称
     * @param string $name   自定义名称
     * @param string $table  数据表名
     * @param string $type   解析类型，本例中为controller、model、validate
     * @return array
     */
    protected function getParseNameData($name, $type)
    {
        $arr = [];
        if (strpos($name, '@')) {
            [$app, $name] = explode('@', $name);
        } else {
            $app = 'common';
        }

        $parseName = ucfirst($name);
        $parseName = $this->convertUnderline($parseName);
        $parseArr = $arr;
        array_push($parseArr, $parseName);

        $path = $this->getNamespace($app,$type);//\api\model    \common\model
        $parseNamespace = "app".$path;
        $path= str_replace('\\', '/', substr($path,1));

        $moduleDir = \think\facade\App::getAppPath() . $path . DIRECTORY_SEPARATOR;

        if($type=="validate" && stristr($parseName,'Validate')==false){
            $parseName = $parseName."Validate";
        }
        $parseFile = $moduleDir . ($arr ? implode(DIRECTORY_SEPARATOR, $arr) . DIRECTORY_SEPARATOR : '') . $parseName . '.php';

        return [$parseNamespace, $parseName, $parseFile, $parseArr];
    }


    /**
     * 写入到文件
     * @param string $name
     * @param array  $data
     * @param string $pathname
     * @return mixed
     */
    protected function writeToFile($name, $data, $pathname)
    {
        foreach ($data as $index => &$datum) {
            $datum = is_array($datum) ? json_encode($datum) : $datum;
        }
        unset($datum);
        $content = $this->getReplacedStub($name, $data);
        if (!is_dir(dirname($pathname))) {
            mkdir(dirname($pathname), 0755, true);
        }
        return file_put_contents($pathname, $content);
    }


    /**
     * 获取替换后的数据
     * @param string $name
     * @param array  $data
     * @return string
     */
    protected function getReplacedStub($name, $data)
    {
        foreach ($data as $index => &$datum) {
            $datum = is_array($datum) ? json_encode($datum) : $datum;
        }
        unset($datum);
        $search = $replace = [];
        foreach ($data as $k => $v) {
            $search[] = $k;
            $replace[] = $v;
        }
        $stubname = $this->getStub($name);
        if (isset($this->stubList[$stubname])) {
            $stub = $this->stubList[$stubname];
        } else {
            $this->stubList[$stubname] = $stub = file_get_contents($stubname);
        }
        $content = str_replace($search, $replace, $stub);
        return $content;
    }


    /**
     * 获取基础模板
     * @param string $name
     * @return string
     */
    protected function getStub($name)
    {
//        return __DIR__ . DIRECTORY_SEPARATOR . 'Crud' . DIRECTORY_SEPARATOR . $name . '.stub';
        return dirname(dirname(__DIR__)) . '/src/tpl/'.$name.".stub";

    }

    /*
 * 下划线转驼峰
 */
    private function convertUnderline($str)
    {
        $str = preg_replace_callback('/([-_]+([a-z]{1}))/i',function($matches){
            return strtoupper($matches[2]);
        },$str);
        return $str;
    }

}