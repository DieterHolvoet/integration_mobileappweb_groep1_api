<?php

require_once '../classes' . DIRECTORY_SEPARATOR . 'DatabasePDO.php';

class WalkDAO {
    const TABLE_NAME = 'walks';
    const DETAILS_TABLE_NAME = 'walk_details';
    const MAPS_TABLE_NAME = 'walk_maps';
    
    /*
     * ADD
     */

    public static function addWalk($languages, $theme_id, $walk_duration, $walk_distance, $walk_thumbnail) {
        if(!Verify::integerWithRange($walk_duration, 0, 1440)) {
            throw new Exception('Duration out of range');
        }

        if(!Verify::integerWithRange($walk_distance, 0, 7000)) {
            throw new Exception('Walk distance out of range');
        }

        if(!ThemeDAO::getThemeByID($theme_id, 1)) {
            throw new Exception("Theme with ID " . $theme_id . " and type " . gettype($theme_id) . " does not exist.");
        }

        $walk_id = DAOTemplate::insert(self::TABLE_NAME, array(
            'theme_id'=>$theme_id,
            'walk_duration'=>$walk_duration,
            'walk_distance'=>$walk_distance,
            'walk_thumbnail'=>$walk_thumbnail['media_filename'],
            'creation_date'=>DAOTemplate::getCurrentDateTime()
        ));

        foreach ($languages as $language_code => $translations) {
            WalkDAO::addTranslation($walk_id, LanguageDAO::getLanguageIDByCode($language_code), $translations['walk_description'], $translations['walk_title']);
        }

        if($walk_id != null){
            return $walk_id;
        } else {
            return false;
        }
    }

    public static function addPoi($walk_id, $languages, $location_id, $stop_sequence) {
        $poi_id = PoiDAO::addPoi($languages, $location_id);
        DAOTemplate::executeSQL("UPDATE " . self::MAPS_TABLE_NAME . " SET stop_sequence=stop_sequence+1 WHERE stop_sequence >= :stop_sequence AND walk_id = :walk_id",
            array('stop_sequence'=>$stop_sequence, 'walk_id'=>$walk_id));
        DAOTemplate::insert(self::MAPS_TABLE_NAME, array(
            'walk_id'=>$walk_id,
            'stop_id'=>$poi_id,
            'stop_type'=>'poi',
            'stop_sequence'=>$stop_sequence
        ));
        return $poi_id;
    }

    public static function addWaypoint($walk_id, $languages, $location, $stop_sequence) {
        $waypoint_id = WaypointDAO::addWaypoint($languages, $location);
        DAOTemplate::executeSQL("UPDATE " . self::MAPS_TABLE_NAME . " SET stop_sequence=stop_sequence+1 WHERE stop_sequence >= :stop_sequence AND walk_id = :walk_id",
            array('stop_sequence'=>$stop_sequence, 'walk_id'=>$walk_id));
        DAOTemplate::insert(self::MAPS_TABLE_NAME, array(
            'walk_id'=>$walk_id,
            'stop_id'=>$waypoint_id,
            'stop_type'=>'waypoint',
            'stop_sequence'=>$stop_sequence
        ));
        return $waypoint_id;
    }

    private static function addTranslation($walk_id, $language_id, $walk_description, $walk_title) {
        return DAOTemplate::insert(self::DETAILS_TABLE_NAME, array(
            'walk_id'=>$walk_id,
            'language_id'=>$language_id,
            'walk_description'=>filter_var($walk_description, FILTER_SANITIZE_STRING),
            'walk_title'=>filter_var($walk_title, FILTER_SANITIZE_STRING)
        ));
    }

    /*
     * GET
     */

    public static function getAll($language_code) {
        $walks = DAOTemplate::getAll(self::TABLE_NAME, "creation_date");
        $language_id = LanguageDAO::getLanguageIDByCode($language_code);

        for($i = 0; $i < count($walks); $i++) {
            $walks[$i] = array_merge($walks[$i], WalkDAO::getTranslation($walks[$i]['walk_id'], $language_id));
            $walks[$i]['theme'] = ThemeDAO::getThemeByID($walks[$i]['theme_id'], $language_id);
            $walks[$i]['walk_thumbnail'] = MediaDAO::getMediaURL($walks[$i]['walk_thumbnail'], MediaDAO::getMediaTypeIDByName('Photo'));

            $avg_location = WalkDAO::getAverageLocation($walks[$i]['walk_id']);
            if($avg_location !== false) {
                $walks[$i]['walk_average_location'] = WalkDAO::getAverageLocation($walks[$i]['walk_id']);
            }

            unset($walks[$i]['walk_detail_id']);
            unset($walks[$i]['language_id']);
            unset($walks[$i]['theme_id']);
        }

        return array('walks'=>$walks);
    }

    public static function getWalkByID($walk_id, $language_id, $isGrouped) {
        $data = DAOTemplate::getByID(self::TABLE_NAME, "walk_id", $walk_id)[0];
        if(!empty($data)) {
            $data['language_id'] = $language_id;
            $data = array_merge($data, WalkDAO::getTranslation($walk_id, $language_id));
            $data['walk_average_location'] = WalkDAO::getAverageLocation($walk_id);
            $data['walk_thumbnail'] = MediaDAO::getMediaURL($data['walk_thumbnail'], MediaDAO::getMediaTypeIDByName('Photo'));

            if($isGrouped) {
                $data = array_merge($data, WalkDAO::getAllStops($walk_id, $language_id));
            } else {
                $data = array_merge($data, WalkDAO::getAllPois($walk_id, $language_id));
                $data = array_merge($data, WalkDAO::getAllWaypoints($walk_id, $language_id));
            }

            $data['theme'] = ThemeDAO::getThemeByID($data['theme_id'], $language_id);

            unset($data['walk_detail_id']);
            unset($data['theme_id']);
        }
        return $data;
    }

    public static function getAllPois($walk_id, $language_id) {
        $stops = DAOTemplate::executeSQL("SELECT * FROM ". self::MAPS_TABLE_NAME ." WHERE walk_id = :walk_id AND stop_type = :stop_type ORDER BY stop_sequence",
            array('walk_id'=>$walk_id, 'stop_type'=>StopTypes::POI));

        for($i = 0; $i < count($stops); $i++) {
            $stops[$i] = WalkDAO::stopToPoi($stops[$i], $language_id);
        }

        return array('pois'=>$stops);
    }

    public static function getAllWaypoints($walk_id, $language_id) {
        $stops = DAOTemplate::executeSQL("SELECT * FROM ". self::MAPS_TABLE_NAME ." WHERE walk_id = :walk_id AND stop_type = :stop_type ORDER BY stop_sequence",
            array('walk_id'=>$walk_id, 'stop_type'=>StopTypes::WAYPOINT));

        for($i = 0; $i < count($stops); $i++) {
            $stops[$i] = WalkDAO::stopToWaypoint($stops[$i], $language_id);
        }

        return array('waypoints'=>$stops);
    }

    private static function getAllStops($walk_id, $language_id) {
        $stops = DAOTemplate::executeSQL("SELECT * FROM " . self::MAPS_TABLE_NAME . " WHERE walk_id = " . $walk_id . " ORDER BY stop_sequence", array());

        for($i = 0; $i < count($stops); $i++) {
            $translation = null;

            switch($stops[$i]['stop_type']) {
                case StopTypes::POI:
                    $stops[$i] = WalkDAO::stopToPoi($stops[$i], $language_id);
                    break;

                case StopTypes::WAYPOINT:
                    $stops[$i] = WalkDAO::stopToWaypoint($stops[$i], $language_id);
                    break;
            }

        }

        return array('stops'=>$stops);
    }

    private static function getAllTranslations($walk_id) {
        $translations = array();
        foreach (LanguageDAO::getAllLanguages() as $language) {
            $translation = WalkDAO::getTranslation($walk_id, $language['language_id']);
            if($translation != null) {
                unset($translation['walk_id']);
                array_push($translations, $translation);
            }
        }
        return $translations;
    }

    public static function getTranslation($walk_id, $language_id) {
        return DAOTemplate::getTranslation(self::DETAILS_TABLE_NAME, 'walk_id', $language_id, $walk_id);
    }

    /*
     * DELETE
     */
    
    public static function deleteWalkByID($walk_id) {
        $success = DAOTemplate::deleteByID(self::TABLE_NAME, 'walk_id', $walk_id);

        if(!$success) return new ErrorObject('Failed deleting walk from ' . self::TABLE_NAME);

        foreach (WalkDAO::getAllTranslations($walk_id) as $translation) {
            if(!WalkDAO::deleteTranslation($translation['walk_detail_id'])) return new ErrorObject('Failed deleting walk translation with ID ' . $translation['walk_detail_id']);
        }

        foreach (WalkDAO::getAllWaypoints($walk_id, LanguageDAO::getLanguageByID(0)) as $waypoint) {
            WaypointDAO::deleteWaypointByID($waypoint['waypoint_id']);
            WaypointDAO::deleteTranslationsByWaypointID($waypoint['waypoint_id']);
        }

        foreach (WalkDAO::getAllPois($walk_id, LanguageDAO::getLanguageByID(0)) as $poi) {
            PoiDAO::deletePoiByID($poi['poi_id']);
            PoiDAO::deleteTranslationsByPoiID($poi['poi_id']);
        }
        
        return true;
    }

    private static function deleteTranslation($walk_detail_id) {
        return DAOTemplate::deleteByID(self::DETAILS_TABLE_NAME, 'walk_detail_id', $walk_detail_id);
    }
    
    /*
     * HELPERS
     */

    private static function getAverageLocation($walk_id) {
        $locations = array();
        $stops = WalkDAO::getAllStops($walk_id, 1)['stops'];

        if(empty($stops)) {
            return false;

        } else {
            foreach($stops as $stop) {
                array_push($locations, array($stop['location']['location_lat'], $stop['location']['location_lon']));
            }
        }

        if(empty($locations)) {
            return false;

        } else {
            $center = LocationHelper::getCenterFromDegrees($locations);
            return array('lat'=>$center[0], 'lon'=>$center[1]);
        }
    }

    private static function getWalkStopsCount($walk_id) {
        $result = DAOTemplate::executeSQL("SELECT MAX(stop_sequence) AS count FROM " . self::MAPS_TABLE_NAME . " WHERE walk_id = " . $walk_id, array());
        return $result[0]['count'];
    }

    private static function stopToPoi($stop, $language_id) {
        $poi_id = $stop['stop_id'];
        $poi = PoiDAO::getPoiByID($poi_id)[0];
        $location_id = $poi['location_id'];
        $translation = PoiDAO::getTranslation($poi_id, $language_id);

        $stop['poi_id'] = $poi_id;
        $stop['location'] = LocationDAO::getLocationByID($location_id, $language_id);
        $stop['media'] = PoiDAO::getMedia($poi_id, $language_id);
        $stop['poi_unlock_code'] = $poi['poi_unlock_code'];

        // unset($translation['poi_detail_id']);
        unset($translation['language_id']);
        unset($stop['stop_id']);
        unset($stop['walk_id']);
        unset($stop['walk_maps_id']);
        unset($stop['stop_type']);

        return array_merge($stop, $translation);
    }

    private static function stopToWaypoint($stop, $language_id) {
        $waypoint_id = $stop['stop_id'];
        $location_id = WaypointDAO::getWaypointByID($waypoint_id)[0]['location_id'];
        $translation = WaypointDAO::getTranslation($waypoint_id, $language_id);

        $stop['waypoint_id'] = $waypoint_id;
        $stop['location'] = LocationDAO::getLocationByID($location_id, $language_id);
        $stop['media'] = WaypointDAO::getMedia($waypoint_id);

        // unset($translation['waypoint_detail_id']);
        unset($translation['language_id']);
        unset($stop['stop_id']);
        unset($stop['walk_id']);
        unset($stop['walk_maps_id']);
        unset($stop['stop_type']);

        return array_merge($stop, $translation);
    }
}