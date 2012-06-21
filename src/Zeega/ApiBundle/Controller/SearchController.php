<?php

/*
* This file is part of Zeega.
*
* (c) Zeega <info@zeega.org>
*
* For the full copyright and license information, please view the LICENSE
* file that was distributed with this source code.
*/

namespace Zeega\ApiBundle\Controller;

use Zeega\DataBundle\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Response;
use Zeega\CoreBundle\Helpers\ResponseHelper;

use DateTime;

/**
* The SearchController handles database search requests made through the API.
* 
*
* @author James Burns <james@zeega.org>
* @author Luis Filipe Brandao <filipe@zeega.org>
*/
class SearchController extends Controller
{
    public function searchAction()
    {
		/**
		* Work in progres - both responses need to be optimized 
		* and should be similar but aren't yet.
		*/

    	$request = $this->getRequest();
        $solrEnabled = $this->container->getParameter('solr_enabled');
		$collectionId = $request->query->get('collection');
        $returnCollections   = $request->query->get('r_collections');
        
		if($solrEnabled)
		{
			if(isset($collectionId))
			{
			    // if we want to get the items of a Collection we need to do a hybrid search to get non indexed items from the database
			    // send db query to doctrine
				$newItemsFromDb = $this->searchWithDoctrine();
				$newItemsFromDbId = array();
				
				// get the results from the DB
				if(array_key_exists("items",$newItemsFromDb)) 
				{
					$dbItems = $newItemsFromDb["items"];
				}
				else if(array_key_exists("collections",$newItemsFromDb))
				{
					$dbItems = $newItemsFromDb["collections"];
				}
				else if(array_key_exists("items_and_collections",$newItemsFromDb))
				{
					$dbItems = $newItemsFromDb["items_and_collections"];
				}
				
				// create a list of items that have to be excluded from the SOLR query because they come from the database
				if(count($dbItems) > 0)
				{
					foreach($dbItems as $newItem)
					{
						array_push($newItemsFromDbId,$newItem->getId());
					}
					$newItemsFromDbId = implode(" OR ", $newItemsFromDbId);
				}
			    //return new Response()
			    // do a SOLR query
				$solrItems = $this->searchWithSolr($newItemsFromDbId);
			}
			else if(isset($returnCollections))
			{
			    // if we only want to collections ()
				return $this->searchWithDoctrineAndGetResponse();
			}
			else
			{
				$dbItems = array();
				$solrItems = $this->searchWithSolr();
			}
			            
		    $itemsView = $this->renderView('ZeegaApiBundle:Search:solr.json.twig', array('new_items'=> $dbItems,'results' => $solrItems["items"], 'tags' => $solrItems["tags"]));
		    return ResponseHelper::compressTwigAndGetJsonResponse($itemsView);
		}
		else
        {
			return $this->searchWithDoctrine();
		}
    }
    
    private function searchWithSolr()
    {	
        $request = $this->getRequest();
        
	    // ----------- api global parameters
		$page  = $request->query->get('page');      //  string
		$limit = $request->query->get('limit');     //  string
	    $q = $request->query->get('q');
	    $userId = $request->query->get('user');                 //  int
	    $username = $request->query->get('username');                 //  int
		$siteId = $request->query->get('site');                 //  int
		$contentType = $request->query->get('content');         //  string
		$collection_id  = $request->query->get('collection');   //  string
		$minDateTimestamp = $request->query->get('min_date');            //  timestamp
		$maxDateTimestamp = $request->query->get('max_date');            //  timestamp
		$geoLocated = $request->query->get('geo_located'); 
		
	    if(!isset($page))               $page = 0;
	    if($page > 0)                   $page = $page - 1;
		if(!isset($limit))              $limit = 100;
		if($limit > 100)                $limit = 100;
		if(isset($contentType))         $contentType = ucfirst($contentType);
		if(!isset($geoLocated))         
		{
			$geoLocated = 0;
		}
		else
		{
			$geoLocated = intval($geoLocated);
		}
		
		if(preg_match('/tag\:(.*)/', $q, $matches))
		{
		 	$q = str_replace("tag:".$matches[1], "", $q);
		 	$tags = "(" . str_replace(",", " OR", $matches[1]) . ")";
		}
		
	    // ----------- build the search query
        $client = $this->get("solarium.client");

        $query = $client->createSelect();
        //return new Response(var_dump($query));
        // pagination and limit
        $query->setRows($limit);
        $query->setStart($limit * $page);
        
        $queryString = '';
        // check if there is a query string
        if(isset($q) and $q != '')                          
        {
            $queryString = "text:$q";
        }
        
        if(isset($tags) and $tags != '')                          
        {
            if($queryString != '')
            {
                $queryString = $queryString . " AND ";
            }
            $queryString = $queryString . "tags_i:$tags";
        }
        
        if(isset($queryString) && $queryString != '')
        {
            $query->setQuery($queryString);
        }
        
        if(isset($contentType) and $contentType != 'All')   $query->createFilterQuery('media_type')->setQuery("media_type:$contentType");
        if($geoLocated > 0)									$query->createFilterQuery('geo')->setQuery("media_geo_longitude:[-180 TO 180] AND media_geo_latitude:[-90 TO 90]");
        if(isset($notInId))									$query->createFilterQuery('not_id')->setQuery("-id:($notInId)");
        if(isset($collection_id))                           $query->createFilterQuery('parent_id')->setQuery("parent_item:$collection_id");
        
        if(isset($minDateTimestamp) || isset($maxDateTimestamp))
        {
            if(isset($minDateTimestamp) && isset($maxDateTimestamp))
            {
                $minDate = new DateTime();
                $minDate->setTimestamp($minDateTimestamp);
                $minDate = $minDate->format('Y-m-d\TH:i:s\Z');
                $maxDate = new DateTime();
                $maxDate->setTimestamp($maxDateTimestamp);
                $maxDate = $maxDate->format('Y-m-d\TH:i:s\Z');
                
                $query->createFilterQuery('media_date_created')->setQuery("media_date_created: [$minDate TO $maxDate]");
            }
        }
        
           
	    //  filter results for the logged user
		if(isset($userId) && $userId == -1) 
		{
			$user = $this->get('security.context')->getToken()->getUser();
			$userId = $user->getId();
		}
	
        if(isset($userId)) $query->createFilterQuery('user_id')->setQuery("user_id: $userId");
        if(isset($username)) $query->createFilterQuery('username')->setQuery("username_i: $username");
        
        $groupComponent = $query->getGrouping();
        $groupComponent->addQuery('-media_type:Collection');
        $groupComponent->addQuery('media_type:Collection');
        $groupComponent->addQuery('media_type:*');
        // maximum number of items per group
        $groupComponent->setLimit($limit);
        $groupComponent->setOffset($page * $limit);
        
        $facetComponent = $query->getFacetSet();
        $facetComponent->createFacetField('tags')->setField('tags_i')->setLimit(5)->setMinCount(1);

        // run the query
        $resultset = $client->select($query);

        $groups = $resultset->getGrouping();
        $facets = $resultset->getFacetSet();
        
        $results["items"] = $groups->getGroup('-media_type:Collection');
        //$results["collections"] = $groups->getGroup('media_type:Collection');
        //$results["items_and_collections"] = $groups->getGroup('media_type:*');

        $tags = $facets->getFacet('tags');
        $tagsArray = array(); 
  
        foreach ($tags as $tag_name => $tag_count)
        {
        	if($tag_count > 0)
        	{
        		$tagsArray[$tag_name] = $tag_count;
        	}
        }
        
        // render the results
		$itemsView = $this->renderView('ZeegaApiBundle:Search:solr.json.twig', array('results' => $results, 'tags' => $tagsArray));
        return ResponseHelper::compressTwigAndGetJsonResponse($itemsView);
    }
    
	/**
     * Action triggered by a search query through the API. 
     * Should be handled with care - it's by no means optimized to handle large databases and
	 * the database queries generated by Doctrine are not optimal.
	 *
     */
    private function searchWithDoctrine()
    {
        $user = $this->get('security.context')->getToken()->getUser();
        if($user == "anon.")
        {
            $em = $this->getDoctrine()->getEntityManager();
            $user = $em->getRepository('ZeegaDataBundle:User')->find(1);
        }
        
	    $request = $this->getRequest();

		$query = array();

		//  api global parameters
		$query["page"]  = $request->query->get('page');      //  string
		$query["limit"] = $request->query->get('limit');     //  string
        		
		//  search specific parameters
		$query["userId"]        = $request->query->get('user');      //  int
		$query["siteId"]  = $request->query->get('site'); //  int
		$query["queryString"]   = $request->query->get('q');         //  string
		$query["contentType"]   = $request->query->get('content');   //  string
		$query["collection_id"]  = $request->query->get('collection');//  string
		$query["tags"]          = $request->query->get('tags');      //  string
		$query["tagsName"]   = $request->query->get('tags_name');      //  string
		$query["dateIntervals"] = $request->query->get('dtintervals');     //  string
		
		// Match tag type searches
		$queryString = $query["queryString"];
		
		if(preg_match('/tag\:(.*)/', $query["queryString"] , $matches))
		{
		 	$query["queryString"] = str_replace("tag:".$matches[1], "", $query["queryString"]);
			$query["tagsName"] = $matches[1];
		}
		
		// convert AND queries to array
		$query["queryString"] = explode(" AND ", $query["queryString"]);
		
		//  read geo params from query
		$query["geo"] = array();
		$query["geo"]["north"] = $request->query->get('geo_n');
		$query["geo"]["south"] = $request->query->get('geo_s');
		$query["geo"]["east"] = $request->query->get('geo_e');
		$query["geo"]["west"] = $request->query->get('geo_w');
		
        $earliestDate = $request->query->get('dtstart');
        $latestDate = $request->query->get('dtend');
        
		//  read date params from query
        if(isset($earliestDate))
        {
            $query["earliestDate"] = new DateTime();
            $query["earliestDate"]->setTimestamp($earliestDate);
        }
        
        if(isset($latestDate))
        {
            $query["latestDate"] = new DateTime();
            $query["latestDate"]->setTimestamp($latestDate);;
        }
    	
		//  check what needs to be returned
		$query["returnMap"]     = $request->query->get('r_map');    				//  bool
		$query["returnTime"]    = $request->query->get('r_time');   				//  bool
		$query["returnItems"]   = $request->query->get('r_items');   				//  bool
		$query["returnItems"]   = $request->query->get('r_items');   				//  bool
		$query["returnCollections"]   = $request->query->get('r_collections');   	//  bool
		$query["returnTags"]   = $request->query->get('r_tags');   	//  bool
		$query["returnCollectionsWithItems"] = $request->query->get('r_itemswithcollections'); //  bool

		//  set defaults for missing parameters  
		if(!isset($query['returnCollections']))     		$query['returnCollections'] = 0;		
		if(!isset($query['returnItems']))           		$query['returnItems'] = 0;
		if(!isset($query['returnTime']))           			$query['returnTime'] = 0;
		if(!isset($query['returnMap']))             		$query['returnMap'] = 0;
		if(!isset($query['returnCollectionsWithItems'])) 	$query['returnCollectionsWithItems'] = 1;
		if(!isset($query['returnTags'])) 					$query['returnTags'] = 0;
		if(!isset($query['returnCounts'])) 					$query['returnCounts'] = 1;
		if(!isset($query['page']))                  		$query['page'] = 0;
		if(!isset($query['limit']))                 		$query['limit'] = 100;
		if($query['limit'] > 100) 	                		$query['limit'] = 100;
	    
	    if( !isset($query["geo"]["north"]) || !isset($query["geo"]["south"]) ||
	        !isset($query["geo"]["east"]) || !isset($query["geo"]["west"]))
	    {
		    $query["geo"] = null;
		}
        
		if(isset($query["contentType"]) && strtoupper($query["contentType"]) == "ALL") 
		{
			$query["contentType"] = null;
	    }
	
	   	if(isset($query["tagsName"]))
	   	{
			$query["tagsName"] = array_map('trim',explode(",",$query["tagsName"] ));
	   	}
	   
	    //  filter results for the logged user
		if(isset($query['userId']) && $query['userId'] == -1) $query['userId'] = $user->getId();
		
		// prepare an array for the results
        $results = array();
		
		$query["arrayResults"] = true;
		
		// regular search - return items and/or collections
		if($query['returnCollections'] || $query['returnItems'] || $query['returnCollectionsWithItems'])
		{
			if($query['returnCollectionsWithItems'])
			{
			     $queryResults = $this->getDoctrine()->getRepository('ZeegaDataBundle:Item')->searchItems($query);
    		    
				// return collections mixed with items
				 $results['items_and_collections'] = $queryResults;
				 $results['returned_items_and_collections_count'] = sizeof($queryResults);
	             $results['items_and_collections_count'] = $this->getDoctrine()->getRepository('ZeegaDataBundle:Item')->getTotalItemsAndCollections($query);
			}
			
			if($query['returnCollections'] || $query['returnItems'])
			{

	            // populate the results object
	            if($query['returnItems'] == 1)
	            {
	                $query["notContentType"] = 'Collection';
	                $items = $this->getDoctrine()->getRepository('ZeegaDataBundle:Item')->searchItems($query);
   			     
	                $results['items'] = $items;
					$results['returned_items_count'] = sizeof($items);
	                $results['items_count'] = $this->getDoctrine()->getRepository('ZeegaDataBundle:Item')->getTotalItems($query);
	            }

	            if($query['returnCollections'] == 1)
	            {
	                $query["notContentType"] = null;
	                $query["contentType"] = 'Collection';
	                
	                $collections = $this->getDoctrine()->getRepository('ZeegaDataBundle:Item')->searchItems($query);
   			     
	                $results['collections'] = $collections;
					$results['returned_collections_count'] = sizeof($collections);
	                $results['collections_count'] = $this->getDoctrine()->getRepository('ZeegaDataBundle:Item')->getTotalCollections($query);
           	}
			}
	    }
		
		if($query['returnTime'])
		{
		    $queryResults = $this->getDoctrine()->getRepository('ZeegaDataBundle:Item')->searchItemsByTimeDistribution($query);
		    $results['time_distribution'] = $queryResults["results"];
		    $results['time_distribution_info'] = array("min_date" => $queryResults["min_date"], 
													   "max_date" => $queryResults["max_date"], "time_intervals" => sizeof($queryResults["results"]));
	    }
	
		if($query['returnTags'])
		{
		    $queryResults = $this->getDoctrine()->getRepository('ZeegaDataBundle:Item')->getQueryTags($query);
		    $results['tags'] = $queryResults;
	    }

		$response = ResponseHelper::getJsonResponse($results);
        
        // return the results
        return $response;
    }
}
