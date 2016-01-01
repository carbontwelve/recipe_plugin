<?php
/*
Plugin Name: [Forked] ZipList Recipe Plugin
Plugin URI: http://www.ziplist.com/recipe_plugin
Plugin GitHub: https://github.com/jeremyfelt/recipe_plugin
Description: A plugin that adds all the necessary microdata to your recipes, so they will show up in Google's Recipe Search
Version: 444.0
Author: ZipList.com
Author URI: http://www.ziplist.com/
License: GPLv3 or later

Copyright 2011, 2012 ZipList, Inc.
This code is derived from the 1.3.1 build of RecipeSEO released by codeswan: http://sushiday.com/recipe-seo-plugin/ and licensed under GPLv2 or later
*/

/*
	This file is part of ZipList Recipe Plugin.

	ZipList Recipe Plugin is free software: you can redistribute it and/or modify
	it under the terms of the GNU General Public License as published by
	the Free Software Foundation, either version 3 of the License, or
	(at your option) any later version.

	ZipList Recipe Plugin is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.

	You should have received a copy of the GNU General Public License
	along with ZipList Recipe Plugin. If not, see <http://www.gnu.org/licenses/>.
*/

// Make sure we don't expose any info if called directly
if ( !function_exists( 'add_action' ) ) {
	echo "Hey!  This is just a plugin, not much it can do when called directly.";
	exit;
}

if (!defined('AMD_ZLRECIPE_VERSION_KEY'))
	define('AMD_ZLRECIPE_VERSION_KEY', 'amd_zlrecipe_version');

if (!defined('AMD_ZLRECIPE_VERSION_NUM'))
	define('AMD_ZLRECIPE_VERSION_NUM', '2.6');

if (!defined('AMD_ZLRECIPE_PLUGIN_DIRECTORY'))
	define('AMD_ZLRECIPE_PLUGIN_DIRECTORY', plugins_url() . '/' . dirname(plugin_basename(__FILE__)) . '/');

register_activation_hook(__FILE__, 'amd_zlrecipe_install');
add_action('plugins_loaded', 'amd_zlrecipe_install');

add_action('admin_head', 'amd_zlrecipe_add_recipe_button');
add_action('admin_head','amd_zlrecipe_js_vars');

function amd_zlrecipe_js_vars() {
	if ( is_admin() ) {
		?>
		<script type="text/javascript">
		var post_id = '<?php global $post; echo $post->ID; ?>';
		</script>
		<?php
	}
}

if (strpos($_SERVER['REQUEST_URI'], 'media-upload.php') && strpos($_SERVER['REQUEST_URI'], '&type=amd_zlrecipe') && !strpos($_SERVER['REQUEST_URI'], '&wrt='))
{
	amd_zlrecipe_iframe_content($_POST, $_REQUEST);
	exit;
}

global $zlrecipe_db_version;
$zlrecipe_db_version = "3.1";	// This must be changed when the DB structure is modified

// Creates ZLRecipe tables in the db if they don't exist already.
// Don't do any data initialization in this routine as it is called on both install as well as
//   every plugin load as an upgrade check.
//
// Updates the table if needed
// Plugin Ver         DB Ver
//   1.0 - 1.3        3.0
//   1.4x - 2.6       3.1  Adds Notes column to recipes table

function amd_zlrecipe_install() {
	global $wpdb;
	global $zlrecipe_db_version;

	$recipes_table = $wpdb->prefix . "amd_zlrecipe_recipes";
	$installed_db_ver = get_option("amd_zlrecipe_db_version");

	if(strcmp($installed_db_ver, $zlrecipe_db_version) != 0) {				// An older (or no) database table exists
		$sql = "CREATE TABLE " . $recipes_table . " (
			recipe_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
			post_id BIGINT(20) UNSIGNED NOT NULL,
			recipe_title TEXT,
			recipe_image TEXT,
			summary TEXT,
			rating TEXT,
			prep_time TEXT,
			cook_time TEXT,
			total_time TEXT,
			yield TEXT,
			serving_size VARCHAR(50),
			calories VARCHAR(50),
			fat VARCHAR(50),
			ingredients TEXT,
			instructions TEXT,
			notes TEXT,
			created_at TIMESTAMP DEFAULT NOW()
			);";

		require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
		dbDelta($sql);

		update_option("amd_zlrecipe_db_version", $zlrecipe_db_version);

	}
}

function amd_zlrecipe_tinymce_plugin($plugin_array) {
	$plugin_array['amdzlrecipe'] = plugins_url( '/zlrecipe_editor_plugin.js?sver=' . AMD_ZLRECIPE_VERSION_NUM, __FILE__ );
	return $plugin_array;
}

function amd_zlrecipe_register_tinymce_button($buttons) {
   array_push($buttons, "amdzlrecipe");
   return $buttons;
}

function amd_zlrecipe_add_recipe_button() {
	// check user permissions
	if ( !current_user_can('edit_posts') && !current_user_can('edit_pages') ) {
		return;
	}

	// check if WYSIWYG is enabled
	if ( get_user_option('rich_editing') == 'true') {
		add_filter('mce_external_plugins', 'amd_zlrecipe_tinymce_plugin');
		add_filter('mce_buttons', 'amd_zlrecipe_register_tinymce_button');
	}
}

function amd_zlrecipe_strip_chars( $val ) {
	return str_replace( '\\', '', $val );
}

// Content for the popup iframe when creating or editing a recipe
function amd_zlrecipe_iframe_content($post_info = null, $get_info = null) {
	$recipe_id = 0;
	if ($post_info || $get_info) {

		if( $get_info["add-recipe-button"] || strpos($get_info["post_id"], '-') !== false ) {
			$iframe_title = "Update Your Recipe";
			$submit = "Update Recipe";
		} else {
			$iframe_title = "Add a Recipe";
			$submit = "Add Recipe";
		}

		if ($get_info["post_id"] && !$get_info["add-recipe-button"] && strpos($get_info["post_id"], '-') !== false) {
			$recipe_id = preg_replace('/[0-9]*?\-/i', '', $get_info["post_id"]);
			$recipe = amd_zlrecipe_select_recipe_db($recipe_id);
			$recipe_title = $recipe->recipe_title;
			$recipe_image = $recipe->recipe_image;
			$summary = $recipe->summary;
			$notes = $recipe->notes;
			$rating = $recipe->rating;
			$ss = array();
			$ss[(int)$rating] = 'selected="true"';
			$prep_time_input = '';
			$cook_time_input = '';
			$total_time_input = '';
			if (class_exists('DateInterval')) {
				try {
					$prep_time = new DateInterval($recipe->prep_time);
					$prep_time_seconds = $prep_time->s;
					$prep_time_minutes = $prep_time->i;
					$prep_time_hours = $prep_time->h;
					$prep_time_days = $prep_time->d;
					$prep_time_months = $prep_time->m;
					$prep_time_years = $prep_time->y;
				} catch (Exception $e) {
					if ($recipe->prep_time != null) {
						$prep_time_input = '<input type="text" name="prep_time" value="' . $recipe->prep_time . '"/>';
					}
				}

				try {
					$cook_time = new DateInterval($recipe->cook_time);
					$cook_time_seconds = $cook_time->s;
					$cook_time_minutes = $cook_time->i;
					$cook_time_hours = $cook_time->h;
					$cook_time_days = $cook_time->d;
					$cook_time_months = $cook_time->m;
					$cook_time_years = $cook_time->y;
				} catch (Exception $e) {
					if ($recipe->cook_time != null) {
						$cook_time_input = '<input type="text" name="cook_time" value="' . $recipe->cook_time . '"/>';
					}
				}

				try {
					$total_time = new DateInterval($recipe->total_time);
					$total_time_seconds = $total_time->s;
					$total_time_minutes = $total_time->i;
					$total_time_hours = $total_time->h;
					$total_time_days = $total_time->d;
					$total_time_months = $total_time->m;
					$total_time_years = $total_time->y;
				} catch (Exception $e) {
					if ($recipe->total_time != null) {
						$total_time_input = '<input type="text" name="total_time" value="' . $recipe->total_time . '"/>';
					}
				}
			} else {
				if (preg_match('(^[A-Z0-9]*$)', $recipe->prep_time) == 1) {
					preg_match('(\d*S)', $recipe->prep_time, $pts);
					$prep_time_seconds = str_replace('S', '', $pts[0]);
					preg_match('(\d*M)', $recipe->prep_time, $ptm, PREG_OFFSET_CAPTURE, strpos($recipe->prep_time, 'T'));
					$prep_time_minutes = str_replace('M', '', $ptm[0][0]);
					preg_match('(\d*H)', $recipe->prep_time, $pth);
					$prep_time_hours = str_replace('H', '', $pth[0]);
					preg_match('(\d*D)', $recipe->prep_time, $ptd);
					$prep_time_days = str_replace('D', '', $ptd[0]);
					preg_match('(\d*M)', $recipe->prep_time, $ptmm);
					$prep_time_months = str_replace('M', '', $ptmm[0]);
					preg_match('(\d*Y)', $recipe->prep_time, $pty);
					$prep_time_years = str_replace('Y', '', $pty[0]);
				} else {
					if ($recipe->prep_time != null) {
						$prep_time_input = '<input type="text" name="prep_time" value="' . $recipe->prep_time . '"/>';
					}
				}

				if (preg_match('(^[A-Z0-9]*$)', $recipe->cook_time) == 1) {
					preg_match('(\d*S)', $recipe->cook_time, $cts);
					$cook_time_seconds = str_replace('S', '', $cts[0]);
					preg_match('(\d*M)', $recipe->cook_time, $ctm, PREG_OFFSET_CAPTURE, strpos($recipe->cook_time, 'T'));
					$cook_time_minutes = str_replace('M', '', $ctm[0][0]);
					preg_match('(\d*H)', $recipe->cook_time, $cth);
					$cook_time_hours = str_replace('H', '', $cth[0]);
					preg_match('(\d*D)', $recipe->cook_time, $ctd);
					$cook_time_days = str_replace('D', '', $ctd[0]);
					preg_match('(\d*M)', $recipe->cook_time, $ctmm);
					$cook_time_months = str_replace('M', '', $ctmm[0]);
					preg_match('(\d*Y)', $recipe->cook_time, $cty);
					$cook_time_years = str_replace('Y', '', $cty[0]);
				} else {
					if ($recipe->cook_time != null) {
						$cook_time_input = '<input type="text" name="cook_time" value="' . $recipe->cook_time . '"/>';
					}
				}

				if (preg_match('(^[A-Z0-9]*$)', $recipe->total_time) == 1) {
					preg_match('(\d*S)', $recipe->total_time, $tts);
					$total_time_seconds = str_replace('S', '', $tts[0]);
					preg_match('(\d*M)', $recipe->total_time, $ttm, PREG_OFFSET_CAPTURE, strpos($recipe->total_time, 'T'));
					$total_time_minutes = str_replace('M', '', $ttm[0][0]);
					preg_match('(\d*H)', $recipe->total_time, $tth);
					$total_time_hours = str_replace('H', '', $tth[0]);
					preg_match('(\d*D)', $recipe->total_time, $ttd);
					$total_time_days = str_replace('D', '', $ttd[0]);
					preg_match('(\d*M)', $recipe->total_time, $ttmm);
					$total_time_months = str_replace('M', '', $ttmm[0]);
					preg_match('(\d*Y)', $recipe->total_time, $tty);
					$total_time_years = str_replace('Y', '', $tty[0]);
				} else {
					if ($recipe->total_time != null) {
						$total_time_input = '<input type="text" name="total_time" value="' . $recipe->total_time . '"/>';
					}
				}
			}

			$yield = $recipe->yield;
			$serving_size = $recipe->serving_size;
			$calories = $recipe->calories;
			$fat = $recipe->fat;
			$ingredients = $recipe->ingredients;
			$instructions = $recipe->instructions;
		} else {
			foreach ($post_info as $key=>$val) {
				$post_info[$key] = stripslashes($val);
			}

			$recipe_id = $post_info["recipe_id"];
			if( !$get_info["add-recipe-button"] )
				 $recipe_title = get_the_title( $get_info["post_id"] );
			else
				 $recipe_title = $post_info["recipe_title"];
			$recipe_image = $post_info["recipe_image"];
			$summary = $post_info["summary"];
			$notes = $post_info["notes"];
			$rating = $post_info["rating"];
			$prep_time_seconds = $post_info["prep_time_seconds"];
			$prep_time_minutes = $post_info["prep_time_minutes"];
			$prep_time_hours = $post_info["prep_time_hours"];
			$prep_time_days = $post_info["prep_time_days"];
			$prep_time_weeks = $post_info["prep_time_weeks"];
			$prep_time_months = $post_info["prep_time_months"];
			$prep_time_years = $post_info["prep_time_years"];
			$cook_time_seconds = $post_info["cook_time_seconds"];
			$cook_time_minutes = $post_info["cook_time_minutes"];
			$cook_time_hours = $post_info["cook_time_hours"];
			$cook_time_days = $post_info["cook_time_days"];
			$cook_time_weeks = $post_info["cook_time_weeks"];
			$cook_time_months = $post_info["cook_time_months"];
			$cook_time_years = $post_info["cook_time_years"];
			$total_time_seconds = $post_info["total_time_seconds"];
			$total_time_minutes = $post_info["total_time_minutes"];
			$total_time_hours = $post_info["total_time_hours"];
			$total_time_days = $post_info["total_time_days"];
			$total_time_weeks = $post_info["total_time_weeks"];
			$total_time_months = $post_info["total_time_months"];
			$total_time_years = $post_info["total_time_years"];
			$yield = $post_info["yield"];
			$serving_size = $post_info["serving_size"];
			$calories = $post_info["calories"];
			$fat = $post_info["fat"];
			$ingredients = $post_info["ingredients"];
			$instructions = $post_info["instructions"];
			if ($recipe_title != null && $recipe_title != '' && $ingredients != null && $ingredients != '') {
				$recipe_id = amd_zlrecipe_insert_db($post_info);
			}
		}
	}

	$recipe_title = esc_attr($recipe_title);
	$recipe_image = esc_attr($recipe_image);
	$prep_time_hours = esc_attr($prep_time_hours);
	$prep_time_minutes = esc_attr($prep_time_minutes);
	$cook_time_hours = esc_attr($cook_time_hours);
	$cook_time_minutes = esc_attr($cook_time_minutes);
	$total_time_hours = esc_attr($total_time_hours);
	$total_time_minutes = esc_attr($total_time_minutes);
	$yield = esc_attr($yield);
	$serving_size = esc_attr($serving_size);
	$calories = esc_attr($calories);
	$fat = esc_attr($fat);
	$ingredients = esc_textarea($ingredients);
	$instructions = esc_textarea($instructions);
	$summary = esc_textarea($summary);
	$notes = esc_textarea($notes);

	$id = (int) $_REQUEST["post_id"];
	$plugindir = AMD_ZLRECIPE_PLUGIN_DIRECTORY;
	$submitform = '';
	if ($post_info != null) {
		$submitform .= "<script>window.onload = amdZLRecipeSubmitForm;</script>";
	}

	echo <<< HTML

<!DOCTYPE html>
<head>
		<link rel="stylesheet" href="$plugindir/zlrecipe-dlog.css" type="text/css" media="all" />
	<script type="text/javascript" src="https://ajax.googleapis.com/ajax/libs/jquery/1.5.1/jquery.min.js"></script>
	<script type="text/javascript">//<!CDATA[

		function amdZLRecipeSubmitForm() {
			var title = document.forms['recipe_form']['recipe_title'].value;

			if (title==null || title=='') {
				$('#recipe-title input').addClass('input-error');
				$('#recipe-title').append('<p class="error-message">You must enter a title for your recipe.</p>');

				return false;
			}
			var ingredients = $('#amd_zlrecipe_ingredients textarea').val();
			if (ingredients==null || ingredients=='' || ingredients==undefined) {
				$('#amd_zlrecipe_ingredients textarea').addClass('input-error');
				$('#amd_zlrecipe_ingredients').append('<p class="error-message">You must enter at least one ingredient.</p>');

				return false;
			}
			window.parent.amdZLRecipeInsertIntoPostEditor('$recipe_id');
			top.tinymce.activeEditor.windowManager.close(window);
		}

		$(document).ready(function() {
			$('#more-options').hide();
			$('#more-options-toggle').click(function() {
				$('#more-options').toggle(400);
				return false;
			});
		});
	//]]>
	</script>
	$submitform
</head>
<body id="amd-zlrecipe-uploader">
	<form enctype='multipart/form-data' method='post' action='' name='recipe_form'>
		<h3 class='amd-zlrecipe-title'>$iframe_title</h3>
		<div id='amd-zlrecipe-form-items'>
			<input type='hidden' name='post_id' value='$id' />
			<input type='hidden' name='recipe_id' value='$recipe_id' />
			<p id='recipe-title'><label>Recipe Title <span class='required'>*</span></label> <input type='text' name='recipe_title' value='$recipe_title' /></p>
			<p id='recipe-image'><label>Recipe Image</label> <input type='text' name='recipe_image' value='$recipe_image' /></p>
			<p id='amd_zlrecipe_ingredients' class='cls'><label>Ingredients <span class='required'>*</span> <small>Put each ingredient on a separate line.  There is no need to use bullets for your ingredients.</small><small>You can also create labels, hyperlinks, bold/italic effects and even add images! <a href="http://marketing.ziplist.com.s3.amazonaws.com/plugin_instructions.pdf" target="_blank">Learn how here</a></small></label><textarea name='ingredients'>$ingredients</textarea></label></p>
			<p id='amd-zlrecipe-instructions' class='cls'><label>Instructions <small>Press return after each instruction. There is no need to number your instructions.</small><small>You can also create labels, hyperlinks, bold/italic effects and even add images! <a href="http://marketing.ziplist.com.s3.amazonaws.com/plugin_instructions.pdf" target="_blank">Learn how here</a></small></label><textarea name='instructions'>$instructions</textarea></label></p>
			<p><a href='#' id='more-options-toggle'>More options</a></p>
			<div id='more-options'>
				<p class='cls'><label>Summary</label> <textarea name='summary'>$summary</textarea></label></p>
				<p class='cls'><label>Rating</label>
					<span class='rating'>
						<select name="rating">
							  <option value="0">None</option>
							  <option value="1" $ss[1]>1 Star</option>
							  <option value="2" $ss[2]>2 Stars</option>
							  <option value="3" $ss[3]>3 Stars</option>
							  <option value="4" $ss[4]>4 Stars</option>
							  <option value="5" $ss[5]>5 Stars</option>
						</select>
					</span>
				</p>
				<p class="cls"><label>Prep Time</label>
					$prep_time_input
					<span class="time">
						<span><input type='number' min="0" max="24" name='prep_time_hours' value='$prep_time_hours' /><label>hours</label></span>
						<span><input type='number' min="0" max="60" name='prep_time_minutes' value='$prep_time_minutes' /><label>minutes</label></span>
					</span>
				</p>
				<p class="cls"><label>Cook Time</label>
					$cook_time_input
					<span class="time">
						<span><input type='number' min="0" max="24" name='cook_time_hours' value='$cook_time_hours' /><label>hours</label></span>
						<span><input type='number' min="0" max="60" name='cook_time_minutes' value='$cook_time_minutes' /><label>minutes</label></span>
					</span>
				</p>
				<p class="cls"><label>Total Time</label>
					$total_time_input
					<span class="time">
						<span><input type='number' min="0" max="24" name='total_time_hours' value='$total_time_hours' /><label>hours</label></span>
						<span><input type='number' min="0" max="60" name='total_time_minutes' value='$total_time_minutes' /><label>minutes</label></span>
					</span>
				</p>
				<p><label>Yield</label> <input type='text' name='yield' value='$yield' /></p>
				<p><label>Serving Size</label> <input type='text' name='serving_size' value='$serving_size' /></p>
				<p><label>Calories</label> <input type='text' name='calories' value='$calories' /></p>
				<p><label>Fat</label> <input type='text' name='fat' value='$fat' /></p>
				<p class='cls'><label>Notes</label> <textarea name='notes'>$notes</textarea></label></p>
			</div>
			<input type='submit' value='$submit' name='add-recipe-button' />
		</div>
	</form>
</body>
HTML;
}

// Inserts the recipe into the database
function amd_zlrecipe_insert_db($post_info) {
	global $wpdb;

	$recipe_id = $post_info["recipe_id"];

	if ($post_info["prep_time_years"] || $post_info["prep_time_months"] || $post_info["prep_time_days"] || $post_info["prep_time_hours"] || $post_info["prep_time_minutes"] || $post_info["prep_time_seconds"]) {
		$prep_time = 'P';
		if ($post_info["prep_time_years"]) {
			$prep_time .= $post_info["prep_time_years"] . 'Y';
		}
		if ($post_info["prep_time_months"]) {
			$prep_time .= $post_info["prep_time_months"] . 'M';
		}
		if ($post_info["prep_time_days"]) {
			$prep_time .= $post_info["prep_time_days"] . 'D';
		}
		if ($post_info["prep_time_hours"] || $post_info["prep_time_minutes"] || $post_info["prep_time_seconds"]) {
			$prep_time .= 'T';
		}
		if ($post_info["prep_time_hours"]) {
			$prep_time .= $post_info["prep_time_hours"] . 'H';
		}
		if ($post_info["prep_time_minutes"]) {
			$prep_time .= $post_info["prep_time_minutes"] . 'M';
		}
		if ($post_info["prep_time_seconds"]) {
			$prep_time .= $post_info["prep_time_seconds"] . 'S';
		}
	} else {
		$prep_time = $post_info["prep_time"];
	}

	if ($post_info["cook_time_years"] || $post_info["cook_time_months"] || $post_info["cook_time_days"] || $post_info["cook_time_hours"] || $post_info["cook_time_minutes"] || $post_info["cook_time_seconds"]) {
		$cook_time = 'P';
		if ($post_info["cook_time_years"]) {
			$cook_time .= $post_info["cook_time_years"] . 'Y';
		}
		if ($post_info["cook_time_months"]) {
			$cook_time .= $post_info["cook_time_months"] . 'M';
		}
		if ($post_info["cook_time_days"]) {
			$cook_time .= $post_info["cook_time_days"] . 'D';
		}
		if ($post_info["cook_time_hours"] || $post_info["cook_time_minutes"] || $post_info["cook_time_seconds"]) {
			$cook_time .= 'T';
		}
		if ($post_info["cook_time_hours"]) {
			$cook_time .= $post_info["cook_time_hours"] . 'H';
		}
		if ($post_info["cook_time_minutes"]) {
			$cook_time .= $post_info["cook_time_minutes"] . 'M';
		}
		if ($post_info["cook_time_seconds"]) {
			$cook_time .= $post_info["cook_time_seconds"] . 'S';
		}
	} else {
		$cook_time = $post_info["cook_time"];
	}

	if ($post_info["total_time_years"] || $post_info["total_time_months"] || $post_info["total_time_days"] || $post_info["total_time_hours"] || $post_info["total_time_minutes"] || $post_info["total_time_seconds"]) {
		$total_time = 'P';
		if ($post_info["total_time_years"]) {
			$total_time .= $post_info["total_time_years"] . 'Y';
		}
		if ($post_info["total_time_months"]) {
			$total_time .= $post_info["total_time_months"] . 'M';
		}
		if ($post_info["total_time_days"]) {
			$total_time .= $post_info["total_time_days"] . 'D';
		}
		if ($post_info["total_time_hours"] || $post_info["total_time_minutes"] || $post_info["total_time_seconds"]) {
			$total_time .= 'T';
		}
		if ($post_info["total_time_hours"]) {
			$total_time .= $post_info["total_time_hours"] . 'H';
		}
		if ($post_info["total_time_minutes"]) {
			$total_time .= $post_info["total_time_minutes"] . 'M';
		}
		if ($post_info["total_time_seconds"]) {
			$total_time .= $post_info["total_time_seconds"] . 'S';
		}
	} else {
		$total_time = $post_info["total_time"];
	}

	$recipe = array (
		"recipe_title" =>  $post_info["recipe_title"],
		"recipe_image" => $post_info["recipe_image"],
		"summary" =>  $post_info["summary"],
		"rating" => $post_info["rating"],
		"prep_time" => $prep_time,
		"cook_time" => $cook_time,
		"total_time" => $total_time,
		"yield" =>  $post_info["yield"],
		"serving_size" =>  $post_info["serving_size"],
		"calories" => $post_info["calories"],
		"fat" => $post_info["fat"],
		"ingredients" => $post_info["ingredients"],
		"instructions" => $post_info["instructions"],
		"notes" => $post_info["notes"],
	);

	if (amd_zlrecipe_select_recipe_db($recipe_id) == null) {
		$recipe["post_id"] = $post_info["post_id"];	// set only during record creation
		$wpdb->insert( $wpdb->prefix . "amd_zlrecipe_recipes", $recipe );
		$recipe_id = $wpdb->insert_id;
	} else {
		$wpdb->update( $wpdb->prefix . "amd_zlrecipe_recipes", $recipe, array( 'recipe_id' => $recipe_id ));
	}

	return $recipe_id;
}

// Inserts the recipe into the post editor
function amd_zlrecipe_plugin_footer() {
	$url = site_url();
	$plugindir = AMD_ZLRECIPE_PLUGIN_DIRECTORY;

	echo <<< HTML
	<style type="text/css" media="screen">
		#wp_editrecipebtns { position:absolute;display:block;z-index:999998; }
		#wp_editrecipebtn { margin-right:20px; }
		#wp_editrecipebtn,#wp_delrecipebtn { cursor:pointer; padding:12px;background:#010101; -moz-border-radius:8px;-khtml-border-radius:8px;-webkit-border-radius:8px;border-radius:8px; filter:alpha(opacity=80); -moz-opacity:0.8; -khtml-opacity: 0.8; opacity: 0.8; }
		#wp_editrecipebtn:hover,#wp_delrecipebtn:hover { background:#000; filter:alpha(opacity=100); -moz-opacity:1; -khtml-opacity: 1; opacity: 1; }
	</style>
	<script>//<![CDATA[
	var baseurl = '$url';          // This variable is used by the editor plugin
	var plugindir = '$plugindir';  // This variable is used by the editor plugin

		function amdZLRecipeInsertIntoPostEditor(rid) {
			tb_remove();

			var ed;

			var output = '<img id="amd-zlrecipe-recipe-';
			output += rid;
						output += '" class="amd-zlrecipe-recipe" src="' + plugindir + '/zlrecipe-placeholder.png" alt="" />';

			if ( typeof tinyMCE != 'undefined' && ( ed = tinyMCE.activeEditor ) && !ed.isHidden() && ed.id=='content') {  //path followed when in Visual editor mode
				ed.focus();
				if ( tinymce.isIE )
					ed.selection.moveToBookmark(tinymce.EditorManager.activeEditor.windowManager.bookmark);

				ed.execCommand('mceInsertContent', false, output);

			} else if ( typeof edInsertContent == 'function' ) {  // path followed when in HTML editor mode
				output = '[amd-zlrecipe-recipe:';
				output += rid;
				output += ']';
				edInsertContent(edCanvas, output);
			} else {
				output = '[amd-zlrecipe-recipe:';
				output += rid;
				output += ']';
				jQuery( edCanvas ).val( jQuery( edCanvas ).val() + output );
			}
		}
	//]]></script>
HTML;
}

add_action('admin_footer', 'amd_zlrecipe_plugin_footer');

// Converts the image to a recipe for output
function amd_zlrecipe_convert_to_recipe($post_text) {
	$output = $post_text;
	$needle_old = 'id="amd-zlrecipe-recipe-';
	$preg_needle_old = '/(id)=("(amd-zlrecipe-recipe-)[0-9^"]*")/i';
	$needle = '[amd-zlrecipe-recipe:';
	$preg_needle = '/\[amd-zlrecipe-recipe:([0-9]+)\]/i';

	if (strpos($post_text, $needle_old) !== false) {
		// This is for backwards compatability. Please do not delete or alter.
		preg_match_all($preg_needle_old, $post_text, $matches);
		foreach ($matches[0] as $match) {
			$recipe_id = str_replace('id="amd-zlrecipe-recipe-', '', $match);
			$recipe_id = str_replace('"', '', $recipe_id);
			$recipe = amd_zlrecipe_select_recipe_db($recipe_id);
			$formatted_recipe = amd_zlrecipe_format_recipe($recipe);
						$output = str_replace('<img id="amd-zlrecipe-recipe-' . $recipe_id . '" class="amd-zlrecipe-recipe" src="' . plugins_url() . '/' . dirname(plugin_basename(__FILE__)) . '/zlrecipe-placeholder.png?ver=1.0" alt="" />', $formatted_recipe, $output);
		}
	}

	if (strpos($post_text, $needle) !== false) {
		preg_match_all($preg_needle, $post_text, $matches);
		foreach ($matches[0] as $match) {
			$recipe_id = str_replace('[amd-zlrecipe-recipe:', '', $match);
			$recipe_id = str_replace(']', '', $recipe_id);
			$recipe = amd_zlrecipe_select_recipe_db($recipe_id);
			$formatted_recipe = amd_zlrecipe_format_recipe($recipe);
			$output = str_replace('[amd-zlrecipe-recipe:' . $recipe_id . ']', $formatted_recipe, $output);
		}
	}

	return $output;
}
add_filter( 'the_content', 'amd_zlrecipe_convert_to_recipe' );

// Pulls a recipe from the db
function amd_zlrecipe_select_recipe_db($recipe_id) {
	global $wpdb;

	$recipe = $wpdb->get_row("SELECT * FROM " . $wpdb->prefix . "amd_zlrecipe_recipes WHERE recipe_id=" . $recipe_id);

	return $recipe;
}

// Format an ISO8601 duration for human readibility
function amd_zlrecipe_format_duration($duration) {
	$date_abbr = array('y' => 'year', 'm' => 'month', 'd' => 'day', 'h' => 'hour', 'i' => 'minute', 's' => 'second');
	$result = '';

	if (class_exists('DateInterval')) {
		try {
			$result_object = new DateInterval($duration);

			foreach ($date_abbr as $abbr => $name) {
				if ($result_object->$abbr > 0) {
					$result .= $result_object->$abbr . ' ' . $name;
					if ($result_object->$abbr > 1) {
						$result .= 's';
					}
					$result .= ', ';
				}
			}

			$result = trim($result, ' \t,');
		} catch (Exception $e) {
			$result = $duration;
		}
	} else { // else we have to do the work ourselves so the output is pretty
		$arr = explode('T', $duration);
		$arr[1] = str_replace('M', 'I', $arr[1]); // This mimics the DateInterval property name
		$duration = implode('T', $arr);

		foreach ($date_abbr as $abbr => $name) {
		if (preg_match('/(\d+)' . $abbr . '/i', $duration, $val)) {
				$result .= $val[1] . ' ' . $name;
				if ($val[1] > 1) {
					$result .= 's';
				}
				$result .= ', ';
			}
		}

		$result = trim($result, ' \t,');
	}
	return $result;
}

add_action( 'wp_enqueue_scripts', 'fys_recipe_enqueue_styles', 40 );
/**
 * Enqueue the CSS used for recipes in posts.
 */
function fys_recipe_enqueue_styles() {
	wp_enqueue_style( 'fys-recipe-css', plugins_url( '/zlrecipe.min.css', __FILE__ ), array(), false );
}

// function to include the javascript for the Add Recipe button
function amd_zlrecipe_process_head() {
	// Always add the print script
	$header_html='<script type="text/javascript" async="" src="' . AMD_ZLRECIPE_PLUGIN_DIRECTORY . 'zlrecipe_print.js"></script>';
	echo $header_html;
}
add_filter('wp_head', 'amd_zlrecipe_process_head');

// Replaces the [a|b] pattern with text a that links to b
// Replaces _words_ with an italic span and *words* with a bold span
function amd_zlrecipe_richify_item($item, $class) {
	$output = preg_replace('/\[([^\]\|\[]*)\|([^\]\|\[]*)\]/', '<a href="\\2" class="' . $class . '-link" target="_blank">\\1</a>', $item);
	$output = preg_replace('/(^|\s)\*([^\s\*][^\*]*[^\s\*]|[^\s\*])\*(\W|$)/', '\\1<span class="bold">\\2</span>\\3', $output);
	return preg_replace('/(^|\s)_([^\s_][^_]*[^\s_]|[^\s_])_(\W|$)/', '\\1<span class="italic">\\2</span>\\3', $output);
}

function amd_zlrecipe_break( $otag, $text, $ctag) {
	$output = "";
	$split_string = explode( "\r\n\r\n", $text, 10 );
	foreach ( $split_string as $str )
	{
		$output .= $otag . $str . $ctag;
	}
	return $output;
}

// Processes markup for attributes like labels, images and links
// !Label
// %image
function amd_zlrecipe_format_item($item, $elem, $class, $itemprop, $id, $i) {

	if (preg_match("/^%(\S*)/", $item, $matches)) {	// IMAGE Updated to only pull non-whitespace after some blogs were adding additional returns to the output
		$output = '<img class = "' . $class . '-image" src="' . $matches[1] . '" />';
		return $output; // Images don't also have labels or links so return the line immediately.
	}

	if (preg_match("/^!(.*)/", $item, $matches)) {	// LABEL
		$class .= '-label';
		$elem = 'div';
		$item = $matches[1];
		$output = '<' . $elem . ' id="' . $id . $i . '" class="' . $class . '" >';	// No itemprop for labels
	} else {
		$output = '<' . $elem . ' id="' . $id . $i . '" class="' . $class . '" itemprop="' . $itemprop . '">';
	}

	$output .= amd_zlrecipe_richify_item($item, $class);
	$output .= '</' . $elem . '>';

	return $output;
}

/*
 * Format the recipe output using proper schema.org markup.
 *
 * Should contain at least 2 of:
 *
 * - image
 * - at least one of prepTime, cookTime, totalTime, or ingredients
 * - nutritionInformation
 * - review
 *
 * Example:
 *
 * <div itemscope itemtype="http://schema.org/Recipe">
 *      <h1 itemprop="name">Grandma's Holiday Apple Pie</h1>
 *      <img itemprop="image" src="apple-pie.jpg" />
 *      By <span itemprop="author" itemscope itemtype="http://schema.org/Person">
 *          <span itemprop="name">Carol Smith</span>
 *      </span>
 *      Published: <time datetime="2009-11-05" itemprop="datePublished">November 5, 2009</time>
 *      <span itemprop="description">This is my grandmother's apple pie recipe. I like to add a dash of nutmeg.</span>
 *      <span itemprop="aggregateRating" itemscope itemtype="http://schema.org/AggregateRating">
 *          <span itemprop="ratingValue">4.0</span> stars based on
 *          <span itemprop="reviewCount">35</span> reviews
 *      </span>
 *      Prep time: <time datetime="PT30M" itemprop="prepTime">30 min</time>
 *      Cook time: <time datetime="PT1H" itemprop="cookTime">1 hour</time>
 *      Total time: <time datetime="PT1H30M" itemprop="totalTime">1 hour 30 min</time>
 *      Yield: <span itemprop="recipeYield">1 9" pie (8 servings)</span>
 *      <span itemprop="nutrition" itemscope itemtype="http://schema.org/NutritionInformation">
 *          Serving size: <span itemprop="servingSize">1 medium slice</span>
 *          Calories per serving: <span itemprop="calories">250 cal</span>
 *          Fat per serving: <span itemprop="fatContent">12 g</span>
 *      </span>
 *      Ingredients:
 *      <span itemprop="recipeIngredient">Thinly-sliced apples: 6 cups</span>
 *      <span itemprop="recipeIngredient">White sugar: 3/4 cup</span>
 *      ...
 *
 *      Directions:
 *      <div itemprop="recipeInstructions">
 *          1. Cut and peel apples
 *          2. Mix sugar and cinnamon. Use additional sugar for tart apples.
 *          ...
 *      </div>
 * </div>
 */
function amd_zlrecipe_format_recipe($recipe) {
	$permalink = get_permalink();

	$output = '
	<div id="zlrecipe-container-' . absint( $recipe->recipe_id ) . '" class="zlrecipe-container-border">
		<div itemscope itemtype="http://schema.org/Recipe" id="zlrecipe-container" class="serif zlrecipe">
			<div id="zlrecipe-innerdiv">
				<div class="item b-b">
					<div class="zlrecipe-print-link fl-r">
						<a class="butn-link" title="Print this recipe" href="javascript:void(0);" onclick="zlrPrint(\'zlrecipe-container-' . $recipe->recipe_id . '\'); return false">Print</a>
					</div>
					<h2 id="zlrecipe-title" itemprop="name" class="b-b h-1 strong" >' . $recipe->recipe_title . '</h2>
				</div>';

	$output .= '<div class="zlmeta zlclear">
					<div class="fl-l width-50">';

	if ( fys_recipe_has_recipe_image() ) {
		$output .='<div class="recipe-image"><img src="' . fys_recipe_get_recipe_image_src( 'medium' ) .'" itemprop="image"></div>';
	}

	//!! close the first container div and open the second
	$output .= '</div><!-- end fl-l -->
				<div class="fl-l width-50">';

	if ( $recipe->prep_time != null ) {
		$prep_time = amd_zlrecipe_format_duration( $recipe->prep_time );

		$output .= '<p id="zlrecipe-prep-time">Prep Time: <time itemprop="prepTime" dateTime="' . $recipe->prep_time . '">' . $prep_time . '</time></p>';
	}

	if ( $recipe->cook_time != null ) {
		$cook_time = amd_zlrecipe_format_duration( $recipe->cook_time );

		$output .= '<p id="zlrecipe-cook-time">Cook Time: <time itemprop="cookTime" dateTime="' . $recipe->cook_time . '">' . $cook_time . '</time></p>';
	}

	if ( $recipe->total_time != null ) {
		$total_time = amd_zlrecipe_format_duration( $recipe->total_time );

		$output .= '<p id="zlrecipe-total-time">Total Time: <time itemprop="totalTime" dateTime="' . $recipe->total_time . '">' . $total_time . '</time></p>';
	}

	if ( $recipe->yield != null ) {
		$output .= '<p id="zlrecipe-yield">Yield: <span itemprop="recipeYield">' . $recipe->yield . '</span></p>';
	}

	if ( $recipe->serving_size != null || $recipe->calories != null || $recipe->fat != null ) {
		$output .= '<div id="zlrecipe-nutrition" itemprop="nutrition" itemscope itemtype="http://schema.org/NutritionInformation">';

		if ( $recipe->serving_size != null ) {
			$output .= '<p id="zlrecipe-serving-size">Serving Size: <span itemprop="servingSize">' . $recipe->serving_size . '</span></p>';
		}

		if ( $recipe->calories != null ) {
			$output .= '<p id="zlrecipe-calories">Calories per serving: <span itemprop="calories">' . $recipe->calories . '</span></p>';
		}

		if ( $recipe->fat != null ) {
			$output .= '<p id="zlrecipe-fat">Fat per serving: <span itemprop="fatContent">' . $recipe->fat . '</span></p>';
		}

		$output .= '</div><!-- end zlrecipe-nutrition -->';
	}

	$output .= '</div><div class="zlclear"></div></div><!-- end zl-meta -->';

	if ( $recipe->summary != null ) {
		$output .= '<div id="zlrecipe-summary" itemprop="description">';
		$output .= amd_zlrecipe_break( '<p class="summary italic">', amd_zlrecipe_richify_item($recipe->summary, 'summary'), '</p>' );
		$output .= '</div>';
	}

	$output .= '<p id="zlrecipe-ingredients" class="h-4 strong">Ingredients</p>';
	$output .= '<ul id=zlrecipe-ingredients-list">';

	$i = 0;
	$ingredients = explode( "\n", $recipe->ingredients );
	foreach ( $ingredients as $ingredient ) {
		$output .= amd_zlrecipe_format_item( $ingredient, 'li', 'ingredient', 'recipeIngredient', 'zlrecipe-ingredient-', $i);
		$i++;
	}

	$output .= '</ul>';

	if ( $recipe->instructions != null ) {
		$instructions = explode( "\n", $recipe->instructions );

		$output .= '<p id="zlrecipe-instructions" class="h-4 strong">Instructions</p>';
		$output .= '<ol id="zlrecipe-instructions-list" class="instructions" itemprop="recipeInstructions">';

		$j = 0;
		foreach ( $instructions as $instruction ) {
			if ( strlen( $instruction ) > 1 ) {
				$output .= '<li id="zlrecipe-instruction-' . $j . '" class="instruction">';
				$output .= amd_zlrecipe_richify_item( $instruction, 'instruction' );
				$output .= '</li>';
				$j++;
			}
		}
		$output .= '</ol>';
	}

	if ( $recipe->notes != null ) {
		$output .= '<p id="zlrecipe-notes" class="h-4 strong">Notes</p>';
		$output .= '<div id="zlrecipe-notes-list">';
		$output .= amd_zlrecipe_break( '<p class="notes">', amd_zlrecipe_richify_item( $recipe->notes, 'notes' ), '</p>' );
		$output .= '</div>';
	}

	// Permalink shown when recipe is printed.
	$output .= '<a id="zl-printed-permalink" href="' . $permalink . '"title="Permalink to Recipe">' . $permalink . '</a>';

	$output .= '</div>';

	// Copyright shown when recipe is printed.
	$output .= '<div id="zl-printed-copyright-statement" itemprop="copyrightHolder">©Feed Your Skull</div>';

	$output .= '</div></div>';

	return $output;
}

add_action( 'after_setup_theme', 'fys_recipe_register_images' );
/**
 * When Multiple Post Thumbnails is an active plugin, register an additional post
 * thumbail for Recipe Image to be used in a recipe and its metadata.
 */
function fys_recipe_register_images() {
	if ( class_exists( 'MultiPostThumbnails' ) ) {
		$image_args = array(
			'label' => 'Recipe Image',
			'id' => 'recipe-image',
			'post_type' => 'post',
		);
		new MultiPostThumbnails( $image_args );
	}
}

/**
 * Determine if a post has an associated recipe image.
 *
 * @return bool
 */
function fys_recipe_has_recipe_image() {
	if ( class_exists( 'MultiPostThumbnails' ) ) {
		return MultiPostThumbnails::has_post_thumbnail( get_post_type(), 'recipe-image' );
	}
	return false;
}

/**
 * Return a recipe image assigned to a post.
 *
 * @param null $size
 *
 * @return bool|mixed
 */
function fys_recipe_get_recipe_image_src( $size = null ) {
	if ( class_exists( 'MultiPostThumbnails' ) ) {
		return MultiPostThumbnails::get_post_thumbnail_url( get_post_type(), 'recipe-image', get_the_ID(), $size );
	}
	return false;
}