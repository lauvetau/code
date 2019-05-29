<?php

namespace app\models;

use Yii;
use yii\helpers\ArrayHelper;
use yii\base\InvalidConfigException;

use app\models\Lot;
use app\models\Portfolio;
use app\models\Contract;
use app\models\Chart;

class PortfolioParser extends \yii\base\Model
{
    const NOT_ENRICHED = 0; // Без обогащения
    const IS_ENRICHED = 1; // С обогащением

    const SWAP_TOTAL = 0; // В % от основного долга
    const SWAP_PRINCIPAL = 1; // В % от общей задолженности

    public $portfolio_file;
    public $name;
    public $enriched;
    public $swap;
    public $partner_id;

    public $registry_file;

    public function rules()
    {
        return [
            ['portfolio_file', 'required', 'message' => 'Необходимо выбрать файл'],
            ['partner_id', 'required', 'message' => 'Необходимо выбрать партнера'],
            ['partner_id', 'integer'],
            ['name', 'string', 'max' => 100, 'message' => 'Максимальная длина - 100 символов'],
            [
                'portfolio_file', 
                'file', 
                'extensions' => 'csv', 
                'checkExtensionByMimeType' => false, 
                'skipOnEmpty' => false,
            ],
            [
                'registry_file', 
                'file', 
                'extensions' => 'zip', 
                'checkExtensionByMimeType' => false, 
                'skipOnEmpty' => false,
            ],
            ['enriched', 'default', 'value' => 0],
            ['swap', 'default', 'value' => 0],
            [
                'registry_file', 
                'file', 
                'extensions' => 'zip', 
                'checkExtensionByMimeType' => false, 
                'skipOnEmpty' => false,
            ],
        ];
    }

    public function attributeLabels()
    {
        return [
            'portfolio_file' => 'Портфель',
            'registry_file' => 'Реестр на оценку',
            'name' => 'Название портфеля',
            'partner_id' => 'Партнер',
            'enriched' => 'Обогащение',
            'swap' => 'Цена',
        ];
    }

    public function uploadPortfolioFile()
    {
        $source = fopen($this->portfolio_file->tempName, 'r');

        fgetcsv($source, 0, ';');

        $portfolio_file_arr = [];

        while ($row = fgetcsv($source, 0, ';')) {
            $portfolio_file_arr[] = array_map(function($val) {
                return iconv('CP1251', 'UTF-8', $val);
            }, $row);
        };

        $transaction = Yii::$app->db->beginTransaction(); 
        
        try {
            $portfolio = Portfolio::getActivePortfolio($this->partner_id);

            $portfolio->name = $this->name ? $this->name : 'Портфель';
            $portfolio->status = Portfolio::ST_LOADED;
            $portfolio->enriched = $this->enriched;
            $portfolio->swap = $this->swap;
            $portfolio->save();

            $portalot = new Lot;
            $portalot->name = 'Портфель';
            $portalot->is_portalot = true;
            $portalot->portfolio_id = $portfolio->id;
            $portalot->contract_count = count($portfolio_file_arr);
            $portalot->credit_debt_total = array_sum(ArrayHelper::getColumn($portfolio_file_arr, 12));
            $portalot->credit_debt_principal = array_sum(ArrayHelper::getColumn($portfolio_file_arr, 13));
            $portalot->save();
            
            $lots = ArrayHelper::index($portfolio_file_arr, null, 0);
            
            unset($portfolio_file_arr);

            $lots_id = [];

            foreach ($lots as $lot_name => $contracts) {
                $lot = new Lot;
                $lot->name = $lot_name;
                $lot->portfolio_id = $portfolio->id;
                $lot->contract_count = count($contracts);
                $lot->credit_debt_total = array_sum(ArrayHelper::getColumn($contracts, 12));
                $lot->credit_debt_principal = array_sum(ArrayHelper::getColumn($contracts, 13));
                $lot->save();

                $lots_id[$lot->id] = [$lot->id];

                foreach ($contracts as $contract_key => $contract) {
                    $contract[0] = $lot->id;

                    foreach ([10, 11] as $key) {
                        $contract[$key] = (new \DateTime($contract[$key]))->format('Y-m-d');
                    }

                    $batch_contracts[] = $contract;
                }
            }
            
            unset($lots);
            unset($contracts);

            $lots_id[$portalot->id] = array_keys($lots_id);

            $columns = Contract::getTableSchema()->columnNames;

            array_shift($columns);
            array_pop($columns);

            Yii::$app->db->createCommand()->batchInsert('contract', $columns, array_values($batch_contracts))->execute();

            $batch_charts = Chart::calculateChart($lots_id);

            $columns = Chart::getTableSchema()->columnNames;

            array_shift($columns);

            Yii::$app->db->createCommand()->batchInsert('chart', $columns, array_values($batch_charts))->execute();

            $portfolio->registry_file_id = $this->saveFile($this->registry_file, File::REGISTRY_DOC);
            $portfolio->update(false, ['registry_file_id']); 
            $transaction->commit();
            Yii::$app->session->setFlash('transactionSuccess', 'Портфель успешно добавлен');

        } catch(\Throwable $e) {
            $transaction->rollBack();
            throw new InvalidConfigException('Не осилил, битый файл или еще какая-то трабла'.PHP_EOL.$e);
        }

        fclose($source);
        return true;
    }

    public function saveFile($file_obj, $file_type)
    {
        $file = new File;
        $file->partner_id = $this->partner_id;
        $file->type = $file_type;
        $file->file = $file_obj;
        $file->upload();
        $file->save(false);
        return $file->id;
    }
}