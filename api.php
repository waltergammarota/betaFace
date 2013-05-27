<?php
define("DEFAULT_API_KEY", 'd45fd466-51e2-4701-8da8-04351c872236');
define("DEFAULT_API_SECRET", '171e8465-f548-401d-b63b-caf0dc28df5f');
define("DEFAULT_API_URL",'http://www.betafaceapi.com/service.svc');
define("DEFAULT_POLL_INTERVAL",1);

class betaFaceApi
{
    var $api_key;
    var $api_secret;
    var $api_url;
    var $poll_interval;
    var $log_level = -1;
    
    function _betaFaceApi($api_key,$api_secret,$api_url,$poll_interval)
    {
        $this->api_key = $api_key;
        $this->api_secret= $api_secret;
        $this->api_url = $api_url;
        $this->poll_interval = $poll_interval;
    }
    
    function betaFaceApi()
    {
        $this->api_key = DEFAULT_API_KEY;
        $this->api_secret= DEFAULT_API_SECRET;
        $this->api_url = DEFAULT_API_URL;
        $this->poll_interval = DEFAULT_POLL_INTERVAL;        
        return true;
    }    

    /**
     * Get face info from BetaFace API by face_uid
     * @param type $face_uid
     * @return type
     */
    function get_face_info($face_uid)
    { 
        $result = $this->api_call('GetFaceImage', array('face_uid' => $face_uid));
        while(!$result['ready'])
        {
            sleep($this->poll_interval);
            $result = $this->api_call('GetFaceImage', array('face_uid' => $face_uid));
        }
        return $result;
    }

    /**
     * Uploads an image to BetaFace API, waits for it to be processed 
     * by polling each poll_interval seconds, and then assigns a person_id
     * (alpha-numberic + '.') to that image.
     * @param type $filename
     * @param type $person_id
     * @return boolean
     */
    function upload_face($filename,$person_id)
    {
        // Step 1: Encode image in base 64, upload it to service and get image ID
        $image_raw = file_get_contents($filename);
        $image_encoded = base64_encode($image_raw);
        $params = array("base64_data" => $image_encoded,"original_filename" => $filename);
        $result = $this->api_call('UploadNewImage_File', $params);
        if(!$result)
        {
            $this->logger("API call to upload image failed!");
            return false;
        } 
        
        // Step 2: keep polling the GetImageInfo endpoint until the processing of the uploaded image is ready.
        $img_uid = $result['img_uid'];
        $result = $this->api_call('GetImageInfo', array('image_uid' => $img_uid));
        while(!$result['ready'])
        {
            sleep($this->poll_interval);
            $result = $this->api_call('GetImageInfo', array('image_uid' => $img_uid));
        }
        
        if($result['face_uid'])
            $face_uid = $result['face_uid'];
        
        // Step 3: associate the face with the person via Faces_SetPerson endpoint
        $params = array('face_uid' => $face_uid, 'person_id' => $person_id);
        $result = $this->api_call('Faces_SetPerson', $params);
        return $result;
    }
    
        
    function recognize_faces($filename, $namespace)
    {
         // Step 1: Encode image in base 64, upload it to service and get image ID
        $image_raw = file_get_contents($filename);
        $image_encoded = base64_encode($image_raw);
        $params = array("base64_data" => $image_encoded,"original_filename" => $filename);
        $result = $this->api_call('UploadNewImage_File', $params);
        if(!$result)
        {
            $this->logger("API call to upload image failed!");
            return false;
        } 
        
        // Step 2: keep polling the GetImageInfo endpoint until the processing of the uploaded image is ready.
        $img_uid = $result['img_uid'];
        $result = $this->api_call('GetImageInfo', array('image_uid' => $img_uid));
        while(!$result['ready'])
        {
            sleep($this->poll_interval);
            $result = $this->api_call('GetImageInfo', array('image_uid' => $img_uid));
        }
        
        if($result['face_uid'])
            $face_uid = $result['face_uid'];
        
        // Step 3: Start a face recognition job
        $params = array('face_uid' => $face_uid, 'namespace' => 'all@'.$namespace);        
        $result = $this->api_call('Faces_Recognize', $params);
        
        // Step 4: Wait for the recognition job to finish
        $params = array('recognize_job_id' => $result['recognize_job_id']);
        $result = $this->api_call('GetRecognizeResult', $params);   
        while(!$result['ready'])
        {
            sleep($this->poll_interval);
            $result = $this->api_call('GetRecognizeResult', $params);
        }                
        
        return $result['matches'];
    }
    
    /**
     * Make an API call to a given endpoint, with given params.
     * This will actually fetch the template from request_templates/endpoint,
     * render it replacing params into XML, POST the
     * data with headers: content_type = application/xml to the BetaFace API,
     * fetch the response and possibly parse it if there is a function
     * available.

     * Returns a dictionary of parsed stuff from the response, or false
     * if the request failed.
     * @param type $endpoint
     * @param type $params
     * @return boolean
     */
    function api_call($endpoint,$params)
    {
        $api_call_params = array_merge(array('api_key'=>$this->api_key,'api_secret'=>$this->api_secret),$params);
        
        $template_name = getcwd()."/request_templates/$endpoint.xml";
        $request_data = $this->render_template($template_name, $api_call_params);
        $url = $this->api_url . '/' . $endpoint;
        $this->logger("Making HTTP request to $url");
        $headers[] = "Content-Type: application/xml";
        
        //open curl connection 
        $ch = curl_init();

        //set the url, POST vars, POST data and headers
        curl_setopt($ch,CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST,1);
        curl_setopt($ch, CURLOPT_POSTFIELDS,$request_data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER,1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        
        $result = curl_exec($ch);
        
        if(!$result)
            $this->logger("Response empty from API");
        
        curl_close($ch);
        
        // Check if parser method exists, and call it with the response from API 
        if (method_exists($this, 'parse_'.$endpoint))
        {
            $this->logger("Using custom response parser for endpoint $endpoint");
            try
            {
                $parsed_result = $this->{'parse_'.$endpoint}($result);
            } catch (Exception $e)
            {
                $this->logger("Error while parsing response: $e");
                return false;
            }
        }
        else
        {
            $this->logger("Custom parsing failed for endpoint $endpoint");
            return false;
        }
        
        return $parsed_result;        
    }
    
    /**
     * Log for debugging
     * @param type $text
     * @param type $level
     */
    function logger($text,$level=0)
    {
        if($this->log_level>$level)
            echo $text."<BR>";
    }

    
    
    
    function render_template($template_file,$context)
    {
        $xml_model = file_get_contents($template_file);
        foreach($context as $param => $value)
        {
            $xml_model = str_replace("{{".$param."}}", $value, $xml_model);
        } 
        return $xml_model;
    }
    
    /**
     * Parse the response from API for UploadNewImage_File method call.
     * @param type $response
     * @return boolean
     */
    function parse_UploadNewImage_File($response)
    {
        $response_xml = simplexml_load_string($response);
        $img_uid = $response_xml->xpath('.//img_uid');
        if (count($img_uid) == 0)
            return false;
        $result['img_uid'] = $img_uid[0];
        
        $ready = $response_xml->xpath(".//int_response");
        if (count($ready) == 0)
            return false;
        
        $result['ready'] = (trim($ready[0]) == '0');
        return $result;
    }
    
    /**
     * Parse the response from API for GetImageInfo method call.
     * @param type $response
     * @return boolean
     */
    function parse_GetImageInfo($response)
    {
        $response_xml = simplexml_load_string($response);
        $ready = $response_xml->xpath(".//int_response");
        if (count($ready) == 0)
            return false;
        
        $result['ready'] = (trim($ready[0]) == '0');

        # If not ready yet, stop parsing at 'ready'
        if (!$result['ready'])
            return $result;

        # Otherwise, see if we have faces
        $face_uids = $response_xml->xpath(".//faces/FaceInfo/uid");
        if (count($face_uids) == 0)
        {
            $this->logger("No faces found in image!");
            return $result;
        }
        $result['face_uid'] = trim($face_uids[0]);
        return $result;
    }
    
    /**
     * Parse the response from API for GetFaceImage method call.
     * @param type $response
     * @return boolean
     */
    function parse_GetFaceImage($response)
    {
        $response_xml = simplexml_load_string($response);
        $ready = $response_xml->xpath(".//int_response");
        if (count($ready) == 0)
            return false;
        
        $result['ready'] = (trim($ready[0]) == '0');

        // If not ready yet, stop parsing at 'ready'
        if (!$result['ready'])
            return $result;

        // Otherwise, see if we have face info
        $face_info = $response_xml->xpath(".//face_info");
        if (count($face_info) == 0)
        {
            $this->logger("No face info found!");
            return $result;
        }
        $result['face_info'] = $face_info[0];
        return $result;
    }    
    
    /**
     * Parse the response from API for Faces_Regognize method call.
     * @param type $response
     * @return boolean
     */
    function parse_Faces_Recognize($response)
    {
        $response_xml = simplexml_load_string($response);
        $recognize_job_id = $response_xml->xpath(".//recognize_uid");
        if (count($recognize_job_id) == 0)
            return false;

        $result['recognize_job_id'] = trim($recognize_job_id[0]);        
        return $result;
    } 
       
    /**
     * Parse the response from API for GetRecognizeResult method call.
     * @param type $response
     * @return boolean
     */
    function parse_GetRecognizeResult($response)
    {
        $response_xml = simplexml_load_string($response);
        $ready = $response_xml->xpath(".//int_response");
        if (count($ready) == 0)
            return false;
        
        $result['ready'] = (trim($ready[0]) == '0');

        // If not ready yet, stop parsing at 'ready'
        if (!$result['ready'])
            return $result;
        
        $matching_persons = $response_xml->xpath(".//faces_matches/FaceRecognizeInfo/matches/PersonMatchInfo");
        if (count($matching_persons) == 0)
        {   
            $this->logger("No matching persons found for image!");
            return false;
        }
        foreach($matching_persons as $matching_person)
        {
            $person_name = trim($matching_person->person_name);
            $confidence = trim($matching_person->confidence);
            $result["matches"][$person_name] = $confidence;
        }
        return $result;
    }
}
?>
