<?php 
    namespace App\Models\Database;

    use CodeIgniter\Model;

    class DBAccess extends Model
    {
        public function selectMultipleTables($data){
            # The Data Format passed as follows
            if(count($data['tables']) == 0){
                return $error = [
                    'error_id' => '0001',
                    'category' => 'Development_Issue',
                    'error_category' => 'DB Related Error',
                    'error_message'  => 'Table name is empty',
                    'data' => $data
                ];
            }

            foreach ($data['tables'] as $tableName) {
                # code...
                if(!$this->db->tableExists($tableName)){
                    return $error = [
                        'error_id' => '0002',
                        'category' => 'Development_Issue',
                        'error_category' => 'DB Related Error',
                        'error_message'  => 'The table: ' . $tableName . ' is not found in DB',
                        'data' => $data
                    ];
                }
            }

            $blueprint = $this->db->table($data['tables']);
            if(!empty($data['distinct']) && $data['distinct']){
                $blueprint->distinct(true);
            }
            $blueprint->select($data['fields']);
            if(!empty($data['sqlConditions'])){
                $query = $blueprint->where($data['sqlConditions']);
            }else{
                $query = $blueprint;
            }
            
            if(!empty($data['order'])){
                foreach ($data['order'] as $order_by => $order) {
                    $query = $blueprint->orderBy($order_by, $order);
                }
            }

            if(isset($data['limit']) && $data['limit'] > 0){
                $query = $blueprint->limit($data['limit']);
            }
            // if(in_array('item', $data['tables'])){
            //     return $data;
            //     die;
            // }

            $return = $query->get()->getResult();
            
            if(empty($return)){
                return $error = [
                    'error_id' => '0004',
                    'category' => 'Data_Issue',
                    'error_category' => 'DB Related Error',
                    'error_message'  => 'Their are no data available',
                    'data' => $data
                ];
            }

            return $return;
        }

        public function insertCommonData($tableName = '', $insertionList = [])
        {

            if($tableName == ''){
                // TODO:: Make a method to handle these error's and return to customer and also make a log for these errors
                return $error = [
                    'error_id' => '0001',
                    'category' => 'Development_Issue',
                    'error_category' => 'DB Related Error',
                    'error_message'  => 'Table name is empty'
                ];
            }

            if(!$this->db->tableExists($tableName)){
                return $error = [
                    'error_id' => '0002',
                    'category' => 'Development_Issue',
                    'error_category' => 'DB Related Error',
                    'error_message'  => 'The table: ' . $tableName . ' is not found in DB'
                ];
            }

            if(empty($insertionList)){
                return $error = [
                    'error_id' => '0005',
                    'category' => 'Development_Issue',
                    'error_category' => 'DB Related Error',
                    'error_message'  => 'Inserting data list is empty'
                ];
            }

            $blueprint = $this->db->table($tableName);
            $blueprint->insert($insertionList);
            $newId = $this->db->insertID(); 
            
            return [
                'success' => true,
                'msg' => 'data insertion of '.$tableName.' is completed',
                'id' => $newId
            ];
        }

        public function updateCommonData($tableName = '',$conditionList = [], $updatedList = [])
        {


            if($tableName == ''){
                return $error = [
                    'error_id' => '0001',
                    'category' => 'Development_Issue',
                    'error_category' => 'DB Related Error',
                    'error_message'  => 'Table name is empty'
                ];
            }

            if(!$this->db->tableExists($tableName)){
                return $error = [
                    'error_id' => '0002',
                    'category' => 'Development_Issue',
                    'error_category' => 'DB Related Error',
                    'error_message'  => 'The table: ' . $tableName . ' is not found in DB'
                ];
            }

            if(empty($conditionList)){
                return $error = [
                    'error_id' => '0003',
                    'category' => 'Development_Issue',
                    'error_category' => 'DB Related Error',
                    'error_message'  => 'Conditions are empty'
                ];
            }
            
            if(empty($updatedList)){
                return $error = [
                    'error_id' => '0007',
                    'category' => 'Development_Issue',
                    'error_category' => 'DB Related Error',
                    'error_message'  => 'Updating data list is empty'
                ];
            }
            // var_dump($conditionList);
            // var_dump($updatedList);

            $blueprint = $this->db->table($tableName);
            $blueprint->where($conditionList);
            foreach ($updatedList as $fieldName => $fieldValue) {
                # code...
                
                $blueprint->set($fieldName, $fieldValue);
            }
            $blueprint->update();

            return  [
                'success' => true,
                'msg' => 'data update of '.$tableName.' is completed'
            ];;
            // var_dump($blueprint);
            // die;
        }
    }
?>