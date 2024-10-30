<?php

namespace Billie\Admin\Option;

abstract class Helper {
	protected $options;

	protected function text_field( $optionName, $fieldName, $default = '' ) {
		printf(
			'<input type="text" id="' . esc_attr( $fieldName ) . '" name="' . esc_attr( $optionName . '[' . $fieldName . ']' ) . '" value="%s" />',
			isset( $this->options[ $fieldName ] ) ? esc_attr( $this->options[ $fieldName ] ) : esc_attr( $default )
		);
	}

	protected function select_field( $optionName, $fieldName, $options, $type = 'single' ) {
		$selectedValue = isset( $this->options[ $fieldName ] ) ? $this->options[ $fieldName ] : '';

		$multiple = '';
		$name     = $optionName . '[' . $fieldName . ']';
		if ( $type === 'multiple' ) {
			$multiple = ' multiple="multiple"';
			$name     .= '[]';
		}

		echo '<select id="' . esc_attr( $fieldName ) . '" name="' . esc_attr( $name ) . '"' . esc_html( $multiple ) . '>';
		foreach ( $options as $value => $label ) {
			$selected = false;
			if ( is_array( $selectedValue ) && $type === 'multiple' ) {
				if ( in_array( $value, $selectedValue, true ) ) {
					$selected = true;
				}
			} elseif ( $selectedValue === $value ) {
				$selected = true;
			}

			if ( $selected ) {
				$selected = ' selected="selected"';
			}
			echo '<option value="' . esc_attr( $value ) . '" ' . esc_html( $selected ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
	}
}
