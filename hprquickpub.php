<?php
/*
Plugin Name: Helpinator Quick Publish
Plugin URI:  http://www.helpinator.com/helpinator-quick-publish.html
Description: Extends WordPress XML-RPC API with functions to quickly publish Helpinator content.
Version:     1.1
Author:      Dmitri Popov
Author URI:  http://www.helpinator.com
License:     GPL3

Copyright 2019 Dmitri Popov (email : popov.dmitri@gmail.com)
"Helpinator Quick Publish" is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 2 of the License, or
any later version.
 
"Helpinator Quick Publish" is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.
 
You should have received a copy of the GNU General Public License
along with (Plugin Name). If not, see http://www.helpinator.com/helpinator-quick-publish-license.html).
*/

function hprquickpub_parse_image_links($contents, $imagemap){
		$tmp = $contents;

		foreach ($imagemap as $attachment_id => $imageurl) {
				$tmp = str_replace('{'.$attachment_id.'}', $imageurl, $tmp);
		}
		
		return $tmp;
}

function hprquickpub_parse_topic_links($contents, $pageids){

		$tmp = $contents;

		foreach ($pageids as $topicid => $page_id) {
				$linkstub = '{' . $topicid . '}';
				$reallink = '?page_id='.$page_id;
				$tmp = str_replace($linkstub, $reallink, $tmp);
				
		}
		
		return $tmp;
		
}

function hprquickpub_publish_topic_stubs($xmlparent, $parentid, &$pageids) {
	
	foreach($xmlparent->children() as $child) {
		$page_id = $child['pageid'];
		$topicid = $child['topicid'];
		$title = $child['title'];		
		$topic = array(
		  'ID'            => $page_id,  
		  'post_title'    => wp_strip_all_tags($title),
		  'post_content'  => "",
		  'post_status'   => 'publish',
		  'post_parent' => $parentid,
		  'post_type' => 'page',
		  'post_name' => $topicid
		);
		
		$topicid = Trim($topicid);
		
		if ($topicid != "") {		
			// Insert the post into the database
			$page_id = wp_insert_post( $topic); 
			
			$page_id = Trim($page_id);

			$pageids[$topicid] = $page_id;
			hprquickpub_publish_topic_stubs($child, $page_id, $pageids);
		}
	}	
}

function hprquickpub_publish_topics($xmlparent, $parentid, $imagemap, $basepath, $pageids, $pagetemplate) {
	foreach($xmlparent->children() as $child) {
		
		$topicid = Trim($child['topicid']);
		$page_id = $pageids[$topicid];
		$title = $child['title'];
		$filename = Trim($child['filename']);
		$keywords = Trim($child['keywords']);
		
		if ($filename != "") {
			$filepath = $basepath . $filename;
			
			$contents = file_get_contents($filepath);
			
			$contents = hprquickpub_parse_image_links($contents, $imagemap);
			$contents = hprquickpub_parse_topic_links($contents, $pageids);
			
			// Create post object
			$topic = array(
			  'ID'            => $page_id,  
			  'post_title'    => wp_strip_all_tags($title),
			  'post_content'  => $contents,
			  'post_status'   => 'publish',
			  'post_parent' => $parentid,
			  'post_type' => 'page',
			  'page_template'  => $pagetemplate,
			  'post_name' => $topicid
			);
	 
			// Insert the post into the database
			$page_id = wp_insert_post($topic);

			wp_set_object_terms($page_id, explode(',', $keywords), 'post_tag', true);
					
			hprquickpub_publish_topics($child, $page_id, $imagemap, $basepath, $pageids, $pagetemplate);
		}
	}
}

function helpinator_quickpub( $args ) {
		
		set_time_limit(0);
		
		global $wp_xmlrpc_server;
		
		$imagemap = array();
		$imageids = array();
		$pageids = array();
		
		
		$username = $wp_xmlrpc_server->escape( $args[1] );
		$password = $wp_xmlrpc_server->escape( $args[2] );
		$data     = $args[3];

		$name = sanitize_file_name( $data['name'] );
		$type = $data['type'];
		$bits = $data['bits'];

		if ( !$user = $wp_xmlrpc_server->login($username, $password) )
			return $wp_xmlrpc_server->error;

		if ( !current_user_can('upload_files') ) {
			$wp_xmlrpc_server->error = new IXR_Error( 401, __( 'Sorry, you are not allowed to upload files.' ) );
			return $wp_xmlrpc_server->error;
		}
		
				
		$upload = wp_upload_bits($name, null, $bits);
		if ( ! empty($upload['error']) ) {
			/* translators: 1: file name, 2: error message */
			$errorString = sprintf( __( 'Could not write file %1$s (%2$s).' ), $name, $upload['error'] );
			return new IXR_Error( 500, $errorString );
		}
		
	
		$zip = new ZipArchive();
		
		$x = $zip->open($upload['file']);
		if ($x === true) {
		
			$targetdir = dirname($upload['file']) . '/' . basename ($upload['file'], '.zip');
			
			if (is_dir($targetdir))  rmdir_recursive ( $targetdir);
			mkdir($targetdir, 0777);			
			
            $zip->extractTo($targetdir); // place in the directory with same name  
            $zip->close();
			
			
			$xml = simplexml_load_file($targetdir . '/meta/settings.xml');
			$parentpage = Trim($xml->parentpage);			
			$pagetemplate = Trim($xml->pagetemplate);
			
			$xml = simplexml_load_file($targetdir . '/meta/images.xml');
			
			//$imagemap = array();
			
			foreach($xml->children() as $child)
			{
				
				$imgid = Trim($child['id']);
				$media_id = Trim($child['mediaid']);
				$imagename = Trim($child['name']);
				
				
				
				if (!empty($media_id)) {
					wp_delete_attachment($media_id, TRUE);
					$media_id = "";
				};

				$filetype = wp_check_filetype( basename( $imagename ), null );

				// Prepare an array of post data for the attachment.
				$attachment = array(
					'guid'           => $targetdir . '/images/' . basename( $imagename ), 
					'post_mime_type' => $filetype['type'],
					'post_title'     => preg_replace( '/\.[^.]+$/', '', basename( $imagename ) ),
					'post_content'   => '',
					'post_status'    => 'inherit'
				);

				// Insert the attachment.
				$attach_id = wp_insert_attachment( $attachment, $targetdir.'/images/'.$imagename, 0 );
				$url = wp_get_attachment_url($attach_id);
				
				
				$imgid = trim($imgid);
				$imagemap[$imgid] = $url;
				$imageids[$imgid] = $attach_id;

				// Make sure that this file is included, as wp_generate_attachment_metadata() depends on it.
				require_once( ABSPATH . 'wp-admin/includes/image.php' );

				// Generate the metadata for the attachment, and update the database record.
				$attach_data = wp_generate_attachment_metadata( $attach_id, $targetdir.'/images/'.$imagename );
				wp_update_attachment_metadata( $attach_id, $attach_data );
				
			}
			
			$xml = simplexml_load_file($targetdir . '/meta/topics.xml');
			hprquickpub_publish_topic_stubs($xml, $parentpage, $pageids);
			hprquickpub_publish_topics($xml, $parentpage, $imagemap, $targetdir . '/topics/', $pageids, $pagetemplate);
			
			$result = array();
			
			$result['images'] = $imageids;
			$result['topics'] = $pageids;
			
						
			return $result;
			
	
		}	

}

function helpinator_new_xmlrpc_methods( $methods ) {
    $methods['helpinator.quickpub'] = 'helpinator_quickpub';
    return $methods;   
}
add_filter( 'xmlrpc_methods', 'helpinator_new_xmlrpc_methods');

?>