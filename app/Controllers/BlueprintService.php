<?php

namespace App\Controllers;

use App\Models\Database\DBAccess;
use Firebase\JWT\JWT;

class BlueprintService extends BaseController
{

    protected $table  = [];
    protected $primaryKeys = [];
    protected $allKeys = [];
    protected $foreignKeys = [];
    
    use Traits\DB\AdminTraits;
    use Traits\DB\PaymentTraits;
    use Traits\DB\PurchaseTraits;
    use Traits\DB\TransactionTraits;

    ################# DB Direct Data Accessing Methodology #################
    public function getDatafromDB($tables, $conditions, $feilds = NULL, $organizer = NULL)
    {
        $module = new DBAccess();
        $allFeilds = [];
        $tableWiseKeys = [];
        $mappedKeys = [];
        $unMappedKeys = [];
        $tableWiseForeignKeys = [];

        $sqlFeild = '';
        $sqlCondtions = '';

        if(count($tables) == 1){
            $table = $tables[0];
            $functionName = $table.'Structure';
            
            // TODO:: handle the function to check if method existing and if not return an Error
            // if(method_exists('AdminTraits',$functionName)){
            // }
            
            # Initate table structures 
            $this->$functionName();
            $allFeilds = $this->allKeys;

        }else if(count($tables) > 1){
            foreach ($tables as $table) {
                $functionName = $table.'Structure';

                # Initate table structures 
                $this->$functionName();
                $tableWiseKeys = $this->allKeys;
                $possibleRelatives[$table] = $this->primaryKeys;
                $tableWiseForeignKeys = $this->foreignKeys;

                foreach ($tableWiseForeignKeys as $foreignKey => $tableName) {
                    # This method remove foreign keys of the tables ih the the search and also make a map for foreign keys.
                    if(in_array($tableName, $tables)){
                        # Below codition validates the table name of the foreign key list with the looping tables to check if we could map the foreign key to the parent key
                        # Note: this is a loop and some feilds are not yet generated. This is why the condtions were handled to capture an 'unmappedKey' list to cature their data later
                        if(isset($allFeilds[$foreignKey])){
                            $mappedKeys[$table.'.'.$tableWiseKeys[$foreignKey]] = $allFeilds[$foreignKey];
                        }else{
                            $unMappedKeys[$foreignKey] = ['table' => $table, 'dbKeys' => $tableWiseKeys[$foreignKey]];
                        }
                        # removal of foreign feilds that have a link with tables used
                        unset($tableWiseKeys[$foreignKey]);
                    }
                }

                array_walk($tableWiseKeys, function (&$item) use ($table) {
                    $item = $table.'.'.$item;
                });
                $allFeilds = array_merge($allFeilds, $tableWiseKeys);
            }

            foreach ($unMappedKeys as $foreignKey => $value) {
                # Used to map the foreign key's of the other tables that were missed in initall map
                if(isset($allFeilds[$foreignKey])){
                    $mappedKeys[$value['table'].'.'.$value['dbKeys']] = $allFeilds[$foreignKey];
                    unset($unMappedKeys[$foreignKey]);
                }
            }

            if(count($unMappedKeys) > 0){
                // TODO:: error when data was not mapped
                return $error = [
                    'error_id' => '0010',
                    'category' => 'Development_Issue',
                    'error_category' => 'Feild Conflict',
                    'error_message'  => 'Table relationship issue detected, plese contact an Admin for a solution',
                    'data' => $unMappedKeys
                ];
            }

            array_unique($allFeilds);
            
            foreach ($mappedKeys as $mappedFeilds => $mappedValue) {
                # getting feilds after converting them to dbKey
                $sqlCondtions .= "$mappedFeilds = $mappedValue AND ";
            }
        }else{
            // TODO:: already handled the table's availablity so will need to return an error here
        }

        if($conditions == NULL){
            $sqlCondtions = '';
        }else{
            foreach ($conditions as $localKey => $conditionValue) {
                # Conditions feild's validation
                if($localKey == 'sqldecrypt'){
                    $sqlCondtions .= $conditionValue;
                }else if(isset($allFeilds[$localKey])){
                    $sqlCondtions .= "$allFeilds[$localKey] = '".$conditionValue."' AND ";
                }else{
                    // TODO:: error when data was not mapped
                    return $error = [
                            'error_id' => '0011',
                            'category' => 'Development_Issue',
                            'error_category' => 'Feild Conflict',
                            'error_message'  => 'The feild '.$localKey.' is not in the tables accessed, please contact the Admin for a soluction',
                            'data' => $allFeilds
                        ];
                }
            }
        }

        # validating the parameter's Feilds directed caller
        if($feilds == NULL){
            $feildList = $allFeilds;
        }else{
            foreach ($feilds as $feild) {
                if(isset($allFeilds[$feild])){
                    $feildList[$feild] = $allFeilds[$feild];
                }else{
                    return $error = [
                        'error_id' => '0013',
                        'category' => 'Development_Issue',
                        'error_category' => 'Feild Conflict',
                        'error_message'  => 'The feild '.$feild.' is not in the tables accessed, please contact the Admin for a soluction',
                        'data' => $allFeilds
                    ];
                }
            }
        }

        foreach ($feildList as $localKey => $dbKey) {
            # getting feilds after converting them to dbKey
            $sqlFeild .= "$dbKey as $localKey, ";
        }
        $sqlFeild = trim($sqlFeild,', ');
        
        # validate the Conditions passed
        $sqlCondtions = trim($sqlCondtions," AND "); //TODO
        $data = [
            'tables' => $tables,
            'fields' => $sqlFeild,
            'sqlConditions' => $sqlCondtions,
        ];

        if(isset($organizer['orderBy']) && $organizer['orderBy'] <> NULL){
            foreach ($organizer['orderBy'] as $ordKey => $order) {
                if (isset($allFeilds[$ordKey])) {
                    $data['order'][$allFeilds[$ordKey]] =  $order;
                }else{
                    return $error = [
                        'error_id' => '0009',
                        'category' => 'Development_Issue',
                        'error_category' => 'Feild Conflict',
                        'error_message'  => 'Order Feild in the Strutural: '.$tables.' the Feild: '.$ordKey
                    ];
                }
            }
        }

        if(isset($organizer['limit']) && $organizer['limit'] <> NULL){
            $data['limit'] =  $organizer['limit'];
        }

        if(isset($organizer['distinct']) && $organizer['distinct'] <> NULL){
            $data['distinct'] = $organizer['distinct'];
        }
        
        $directData = json_decode(json_encode($module->selectMultipleTables($data)),true);
        
        if(isset($directData['error_id'])){
            return $directData;
        }

        if(count($directData) == 1){
            return $directData[0];
        }
        return $directData;
    }
    
    public function insertDatatoDB($table, $insertList)
    {
        $functionName = $table.'Structure';

        # Initate table structures 
        $this->$functionName();
        
        # validate the Conditions passed
        if ($insertList <> NULL) {
            foreach ($insertList as $frontend => $value) {
                if (isset($this->allKeys[$frontend])) {
                    $proccessedDataList[$this->allKeys[$frontend]] =  $value;
                }else{
                    return $error = [
                        'error_id' => '0006',
                        'category' => 'Development_Issue',
                        'error_category' => 'Feild Conflict',
                        'error_message'  => 'Insert Feild in the Strutural: '.$table.' the Feild: '.$value,
                        'feilds' => $frontend
                    ];
                }
            }
        }

        // Foreign Tables linked have data verification process
        foreach ($this->foreignKeys as $foreignFeild => $foreignTable) {
            // Optional (nullable) foreign keys that were not supplied are skipped;
            // the DB level constraint still guards any value that is supplied.
            if (! isset($insertList[$foreignFeild]) || $insertList[$foreignFeild] === '') {
                continue;
            }

            $validation = $this->getDatafromDB([$foreignTable], [$foreignFeild => $insertList[$foreignFeild]], [$foreignFeild]);

            if(isset($validation['error_id'])){
                return $validation;
            }

            # getDatafromDB() swaps the structure in use, re-initiate the table being inserted
            $this->$functionName();
        }

        $module = new DBAccess();
        $return = $module->insertCommonData($table,$proccessedDataList);

        if (isset($return['success']) && $return['success']) {
            return $return;
        }

        return isset($return['error_id']) ? $return : [
            'error_id' => '0005',
            'category' => 'Development_Issue',
            'error_category' => 'DB Related Error',
            'error_message'  => 'Data insertion of '.$table.' failed'
        ];
    }

    public function updateDBData($table, $conditionList, $updatedList)
    {
        $functionName = $table.'Structure';

        $condition = [];
        $update = [];
        
        # Initate table structures 
        $this->$functionName();
        
        # validate the Conditions passed
        if ($conditionList <> NULL) {
            foreach ($conditionList as $frontend => $value) {
                if (isset($this->allKeys[$frontend])) {
                    $condition[$this->allKeys[$frontend]] =  $value;
                }else{
                    return $error = [
                        'error_id' => '0008',
                        'category' => 'Development_Issue',
                        'error_category' => 'Feild Conflict',
                        'error_message'  => 'Conditional Feild in the Strutural: '.$table.' the Feild: '.$value
                    ];
                }
            }
        }

        # validate the Conditions passed
        if ($updatedList <> NULL) {
            foreach ($updatedList as $updateKey => $updateValue) {
                if (isset($this->allKeys[$updateKey])) {
                    $update[$this->allKeys[$updateKey]] =  $updateValue;
                }else{
                    return $error = [
                        'error_id' => '0012',
                        'category' => 'Development_Issue',
                        'error_category' => 'Feild Conflict',
                        'error_message'  => 'Conditional Feild in the Strutural: '.$table.' the Feild: '.$updateValue
                    ];
                }
            }
        }

        $module = new DBAccess();
        $return = $module->updateCommonData($table,$condition, $update);

        if (isset($return['success']) && $return['success']) {
            return $return;
        }

        return isset($return['error_id']) ? $return : [
            'error_id' => '0007',
            'category' => 'Development_Issue',
            'error_category' => 'DB Related Error',
            'error_message'  => 'Data update of '.$table.' failed'
        ];
    }

    public function handlePOSTBodyDataList()
    {
        return $this->request->getPost();
    }

    public function handlePOSTAPIBodyDataList()
    {
        return $this->request->getJson('request_data');
    }

    public function errorHandleLogAndPageRedirection($errorDataList, $redirect = '')
    {
        if($redirect != ''){
            return redirect()->to(base_url($redirect))->with('msg', $errorDataList['error_message']);
        }

    }

    public function errorHandleforAPIResponses($errorDataList)
    {
        $response = [
            'data' => [
                'success' => false,
                'code' => $errorDataList['error_id'],
                'response' => $errorDataList['error_category'],
                'message' => $errorDataList['error_message'],
                // 'data' => $errorDataList['data']
            ],
            'code' => 401
        ];

        if(isset($errorDataList['data'])){
            $response['data']['reason'] = $errorDataList['data'];
        }

        if($errorDataList['category'] == 'Format_Issue'){
            $response['restype'] = 'response';
        }else{
            $response['restype'] = 'fail';
        }
            
        
        echo json_encode($response['data']);
        exit;
    }

    private function getSecretKey(){
        return bin2hex('343sdsdfsdsdsRSDsGVfsdXC56ssd343DDGHGNFG6sdfs37HVB4333sdffe34dFd');
    }

    public function generateJWT($data)
    {
        $secretKey = $this->getSecretKey();
        $payload = [
            'iss' => base_url(),
            'aud' => base_url(),
            'iat' => time(),
            'nbf' => time(),
            'exp' => time() + 60, 
            'data' => $data
        ];

        $jwt = JWT::encode($payload, $secretKey, 'HS256');
        return $jwt;
    }

    public function decodeJWT($jwt){
        $secretKey = $this->getSecretKey();
        
        $decoded = JWT::decode($jwt, new \Firebase\JWT\Key($secretKey, 'HS256'));

        return $decoded;
    }

}