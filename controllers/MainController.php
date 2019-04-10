<?php namespace Applications\Category\Controllers;


/**
 * 分类主控制器
 */
class MainController{

    /**
     * 分类注册列表
     */
    public function apiCategoryConfig($param){
        $allModels = [];

        foreach (cms('apps') as $key => $value) {
            if(!isset($value["depends"]["category"]["models"])) continue;
            $models = $value["depends"]["category"]["models"];
            foreach ($models as $value) {
                $allModels[] = $value;
            }
        }
        return $allModels;
    }
    
    /**
     * 所有分类
     */
    public function apiCategories($param){
        $categories = [];
        if(isset($param['filter']) && is_array($param['filter'])){
            $filter = $param['filter'];
        }else{
            input("filter",$filter);
        }
        $records = model('category');
        foreach ($filter as $key => $value) {
        	$records = $records->where($key,$value);
        }
        $records = $records->orderBy('order',"ASC")->get();

        foreach ($records as $value) {
            $categories[$value->model][$value->id] = (array)$value;
        }
        foreach ($categories as $key=>$value) {
            $categories[$key] = $this->makeCatTree($value);
        }
        
        return mr($categories);
    }
    
    
    /**
     * https://stackoverflow.com/questions/17597780/hierarchy-tree-to-get-parent-slug/17598761#17598761
     */
    private function makeCatTree($categories){
    	
    	$categories = json_decode(json_encode($categories), true);
        
        // First of all we sort the categories array by parent id!
        // We need the parent to be created before teh children after all?
        $parent_ids = array();
        foreach($categories as $k => $cat) {
            $parent_ids[$k] = $cat['order'];
        }
        array_multisort($parent_ids, SORT_ASC, $categories);
    
        /* note: at this point, the categories are now sorted by the parent_id key */
    
        // $new contains the new categories array which you will pass into the tree function below (nothign fancy here)
        $new = array();
    
        // $refs contain references (aka points) to places in the $new array, this is where the magic happens!
        // without references, it would be difficult to have a completely random mess of categories and process them cleanly
        // but WITH references, we get simple access to children of children of chilren at any point of the loop
        // each key in this array is teh category id, and the value is the "children" array of that category
        // we set up a default reference for top level categories (parent id = 0) 
        $refs = array(0=>&$new);
    
        // Loop teh categories (easy peasy)
        foreach($categories as $c) {
    
            // We need the children array so we can make a pointer too it, should any children categories popup
            $c['children'] = array();
    
            // Create the new entry in the $new array, using the pointer from $ref (remember, it may be 10 levels down, not a top level category) hence we need to use the reference/pointer
            $refs[$c['parent_id']][$c['id']] = $c;
    
            // Create a new reference record for this category id
            $refs[$c['id']] = &$refs[$c['parent_id']][$c['id']]['children'];
    
        }
        
        return $new;
    }
    
    
    /**
     * 某个实例上的分类
     * @param modelConfig|绑定模型配置|是--relation|对应的模型关系名称|是--id|实例ID|是
     */
    public function apiModelCategories($param){
    	input('relation',$relation);
    	input('modelConfig',$modelConfig);
    	
    	$oldCats = [];
    	
    	$relationCats = model($modelConfig)->relation($relation)->where('model_id',input('id'))->get();
    	
    	foreach ($relationCats as $cat) {
    		$oldCats[] = $cat->relation_id;
    	}
    	
    	$_categories = [];
    	
        $categories = model('category')->orderBy('id',"desc");
        
        if(intval(input('model_id'))){
            $categories = $categories->where("model_id",input('model_id'));
        }
        $categories = $categories->where('model',explode(".",$modelConfig)[0]."-".$relation)->get();
        
        foreach($categories as $category){
        	if(in_array($category->id,$oldCats)){
        		$category->checked = true;
        	}else{
                $category->checked = false;
            }
        	$_categories[] = $category;
        }
        
        $categories = $this->makeCatTree($_categories);

        return mr($categories);
    }
    
    /**
     * 创建分类
     * @param name|分类名称|是--model|关联模型|是
     */
    public function apiCreate(){
        $input = input();
        $rules = [
            "name"=>"名称必填哦",
            "model"=>"模型配置必填哦"
        ];
        if(!validator($errors,$rules)){
            return mr(null,-1,$errors[0]);
        }
        $err = model('category')->create([
            'name'=>$input['name'],
            'model'=>$input['model'],
            'model_id'=>input('model_id')
        ]);
        if($err){
            return mr(null,-2,$err);
        }
        
        $data = mr(null);
        
        $categories = model('category')->orderBy('order',"ASC");
        if(intval(input('model_id'))>0){
            $categories->where("model_id",input('model_id'));
        }
        $categories = $categories->where('model',$input['model'])->get();
        
        $categories = $this->makeCatTree($categories);
        
        $data["#".$input['model']] = \Web::component('category@cat-tree',['categories'=>$categories]);
        
        return $data;
    }
    
    
    /**
     * 删除分类
     * @param id|分类ID|是
     */
    public function apiDelete(){
        $input = input();
        $category = model('category')->find($input['id']);
        if($category){
            $allCat = model('category')->where('model',$category->model)->get();
            $this->recursiveDeleteCat($allCat,$category);

            $categories = model('category')->orderBy('order',"ASC");
            if($category->model_id>0){
                $categories = $categories->where("model_id",$category->model_id);
            }
            $categories = $categories->where('model',$category->model)->get();
            $categories = $this->makeCatTree($categories);
            $data = mr(null,1,"删除成功");
            $data["#".$category->model] = \Web::component('category@cat-tree',['categories'=>$categories]);
            return $data;
        }
    }
    private function recursiveDeleteCat($allCat,$category){
        foreach ($allCat as $cat) {
            if($cat->parent_id == $category->id){
                $this->recursiveDeleteCat($allCat,$cat);
            }
        }
        model('category')->where("id",$category->id)->delete();
    }
    /**
     * 排序分类
     * @param json|排序后的分类数据|是
     */
    public function apiReorder(){
        input('json',$cat);
        $i = 0;
        $err = $this->reorderCat($i,json_decode($cat,true));
        if($err){
        	return mr(null,-1,$err);
        }
        return mr(null);
    }
    
    private function reorderCat(&$i,$cats,$parent_id = 0){
        foreach ($cats as $index=>$cat) {
            $err = model('category')->where("id",$cat["id"])->update(["parent_id"=>$parent_id,"order"=>$i]);
            if($err){
            	return $err;
            }
            $i = $i + 1;
            if(isset($cat['children']) && count($cat['children'])>0){
                $this->reorderCat($i,$cat['children'],$cats[$index]['id']);
            }
        }
    }
    
    /**
     * 绑定分类
     * @param modelConfig|绑定模型配置|是--relation|对应的模型关系名称|是--categories|分类ID列表|是
     */
    public function apiBind($param){
    	
    	$relationModel = model(input('modelConfig'))->relation(input('relation'));
    	
    	$categories = input('categories');
    	if(!is_array($categories)){
    		$relationModel->where('model_id',input('id'))->delete();
    		return mr(null,1,"保存成功");
    	}
    	
    	$oldCats = [];
		$relations = $relationModel->where('model_id',input('id'))->get();
		foreach ($relations as $relation) {
			$oldCats[] = $relation->relation_id;
		}
		
		$newCats = [];
		foreach ($categories as $cat) {
			if(is_array($cat)){
				$newCats[] = array_shift($cat);
			}else{
				$newCats[] = $cat;
			}
		}
		//create new category relation
		$toCreate = array_diff($newCats,$oldCats);
		if(is_array($toCreate)){
			foreach ($toCreate as $key => $value) {
				$err = $relationModel->create([
					'model_id'=> input('id'),
					'relation_name'=>input('relation'),
					'relation_id'=>$value
				]);
				if($err){
					\Log::error($err);
					return mr(null,-1,$err);
				}
			}
		}
		
		//delete removed relation
		$toDelete = array_diff($oldCats,$newCats);
		if(is_array($toDelete)){
			foreach ($toDelete as $value) {
				$err = $relationModel->where('model_id',input('id'))->where('relation_id',$value)->delete();
				if($err){
					\Log::error($err);
					return mr(null,-1,$err);
				}
			}
		}
    	
    	return mr(null,1,"保存成功");
    }
}