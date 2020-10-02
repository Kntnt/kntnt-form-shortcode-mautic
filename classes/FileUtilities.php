<?php


namespace Kntnt\Form_Shortcode_Mautic;


trait FileUtilities {

    // Returns the absolute path to the file with the path `$file` relative
    // the plugin's includes directory to a file.
    public static final function template( $file ) {
        return Plugin::plugin_dir( "includes/$file" );
    }

    // Import the file with the absolute path `$template_file`. If the file
    // contains PHP-code, it is evaluated in a context where the each element
    // of associative array `$template_variables` is converted into a variable
    // with the name and value of the elements key and value, respectively. The
    // resulting content is included at the point of execution of this function
    // if  `$return_template_as_string` is false (default), otherwise returned
    // as a string.
    public static final function include_template( $template_file, $template_variables = [], $return_template_as_string = false ) {
        extract( $template_variables, EXTR_SKIP );
        if ( $return_template_as_string ) {
            ob_start();
        }
        require self::template( $template_file );
        if ( $return_template_as_string ) {
            return ob_get_clean();
        }
    }

    // Saves $content to a file in a subdirectory of WordPress' upload
    // directory. If `$subdir` is given, it's used as the subdirectory's path
    // relative the upload directory (any leading slash is removed), otherwise
    // the plugin's name space is used as name of the subdirectory. If `$name`
    // is given, it's used as the name of the file exclusive its suffix,
    // otherwise the plugin's name space is used as name of the file. If
    // `$suffix` is given, it's used as the file's suffix, otherwise "txt" is
    // used. If `$replace` is `true`, an existing file with the same name is
    // deleted before saving, otherwise the file name is enumerated. If
    // `$save_empty_file` is `true`, `$content` is saved whether it has content
    // or not, otherwise it's saved only if it's non-empty.
    public static final function save_to_file( $content, $suffix = 'txt', $name = null, $subdir = null, $replace = true, $save_empty_file = false ) {
        $subdir = trim( $subdir ?: Plugin::ns(), '/' );
        $upload_dir_filter = function ( $upload_dir ) use ( $subdir ) {
            $upload_dir['path'] = "{$upload_dir['basedir']}/$subdir";
            $upload_dir['url'] = "{$upload_dir['baseurl']}/$subdir";
            $upload_dir['subdir'] = "$subdir";
            return $upload_dir;
        };
        add_filter( 'upload_dir', $upload_dir_filter );
        $file_name = ( $name ?: Plugin::ns() ) . ".$suffix";
        $file_path = wp_upload_dir()['path'] . "/$file_name";
        $file_info = [
            'file' => '',
            'url' => '',
            'type' => '',
            'error' => false,
        ];
        if ( $replace && file_exists( $file_path ) ) {
            $file_info['error'] = ! @unlink( $file_path );
        }
        if ( ! $file_info['error'] && ( $content || $save_empty_file ) ) {
            $file_info = wp_upload_bits( $file_name, null, $content );
            $file_info['error'] = (bool) $file_info['error'];
        }
        remove_filter( 'upload_dir', $upload_dir_filter );
        return $file_info;
    }

}