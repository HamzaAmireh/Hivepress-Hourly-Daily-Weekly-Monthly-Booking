/**
 * Price toggle functionality for HivePress Marketplace
 * 
 * Handles toggling between hourly pricing and package pricing fields
 * and synchronizes values between price fields
 */
(function($) {
    'use strict';
    
    var PriceToggle = {
        // Store field references
        fields: {},
        
        // Initialize the functionality
        init: function() {
            this.initializeFields();
            this.bindEvents();
            console.log('PriceToggle initialized');
        },
        
        // Find and store field references
        initializeFields: function() {
            // Clear old field references
            this.unbindEvents();
            this.fields = {};
            
            // Helper function to find field with or without hp_ prefix
            var findField = function(name) {
                return $('input[name="' + name + '"], input[name="hp_' + name + '"]');
            };
            
            // Find new fields
            this.fields = {
                checkbox: findField('enable_price_hour'),
                hourlyPrice: findField('price_hour'),
                regularPrice: findField('price'),
                price7: findField('price_7'),
                price30: findField('price_30')
            };
            
            console.log('Fields found:', {
                checkbox: this.fields.checkbox.length > 0 ? this.fields.checkbox.attr('name') : 'not found',
                hourlyPrice: this.fields.hourlyPrice.length > 0 ? this.fields.hourlyPrice.attr('name') : 'not found',
                regularPrice: this.fields.regularPrice.length > 0 ? this.fields.regularPrice.attr('name') : 'not found',
                price7: this.fields.price7.length > 0 ? this.fields.price7.attr('name') : 'not found',
                price30: this.fields.price30.length > 0 ? this.fields.price30.attr('name') : 'not found'
            });
            
            this.fields.hourlyField = this.fields.hourlyPrice.closest('.hp-form__field');
            this.fields.regularField = this.fields.regularPrice.closest('.hp-form__field');
            this.fields.field7 = this.fields.price7.closest('.hp-form__field');
            this.fields.field30 = this.fields.price30.closest('.hp-form__field');
            
            // Initial toggle
            if (this.fields.checkbox.length) {
                this.togglePriceFields();
            }
        },
        
        // Unbind all event listeners
        unbindEvents: function() {
            if (this.fields.checkbox) {
                this.fields.checkbox.off('change');
            }
            if (this.fields.hourlyPrice) {
                this.fields.hourlyPrice.off('input change');
            }
            if (this.fields.regularPrice) {
                this.fields.regularPrice.off('input change');
            }
            console.log('Events unbound');
        },
        
        // Bind all event listeners
        bindEvents: function() {
            if (!this.fields.checkbox.length) {
                console.log('No checkbox found, skipping event binding');
                return;
            }
            
            var self = this;
            
            // Checkbox change
            this.fields.checkbox.on('change', function() {
                console.log('Checkbox changed:', $(this).is(':checked'));
                self.togglePriceFields();
            });
            
            // Hourly price changes
            this.fields.hourlyPrice.on('input change', function() {
                if (self.fields.checkbox.is(':checked') && $(this).val()) {
                    self.fields.regularPrice.val($(this).val());
                }
            });
            
            // Regular price changes
            this.fields.regularPrice.on('input change', function() {
                if (!self.fields.checkbox.is(':checked') && $(this).val()) {
                    self.fields.hourlyPrice.val($(this).val());
                }
            });
            
            // Form submission
            $('form').off('submit.priceToggle').on('submit.priceToggle', function() {
                if (self.fields.checkbox.is(':checked') && self.fields.hourlyPrice.val()) {
                    self.fields.regularPrice.val(self.fields.hourlyPrice.val());
                } else if (!self.fields.checkbox.is(':checked') && self.fields.regularPrice.val()) {
                    self.fields.hourlyPrice.val(self.fields.regularPrice.val());
                }
                return true;
            });
            
            console.log('Events bound');
        },
        
        // Toggle price fields visibility
        togglePriceFields: function() {
            var isChecked = this.fields.checkbox.is(':checked');
            console.log('Toggling fields, hourly pricing enabled:', isChecked);
            
            if (isChecked) {
                // Hourly pricing is enabled
                this.fields.hourlyField.show();
                this.fields.regularField.hide();
                this.fields.field7.hide();
                this.fields.field30.hide();
                
                // Copy hourly price to regular price (if hourly has a value)
                if (this.fields.hourlyPrice.val()) {
                    this.fields.regularPrice.val(this.fields.hourlyPrice.val());
                }
            } else {
                // Package pricing is enabled
                this.fields.hourlyField.hide();
                this.fields.regularField.show();
                this.fields.field7.show();
                this.fields.field30.show();
                
                // Copy regular price to hourly price (if regular has a value)
                if (this.fields.regularPrice.val()) {
                    this.fields.hourlyPrice.val(this.fields.regularPrice.val());
                }
            }
        },
        
        // Reinitialize after AJAX
        reinitialize: function() {
            console.log('Reinitializing PriceToggle');
            this.initializeFields();
            this.bindEvents();
        }
    };
    
    // Initialize on document ready
    $(document).ready(function() {
        setTimeout(function() {
            PriceToggle.init();
        }, 500);
    });
    
    // Handle category changes and AJAX updates
    $(document).on('change', 'select[name="listing_category"]', function() {
        console.log('Category changed, scheduling reinitialization');
        setTimeout(function() {
            PriceToggle.reinitialize();
        }, 1000); // Increased timeout to ensure AJAX content is loaded
    });
    
    // Listen for HivePress content updates
    $(document).on('hivepress:init', function(event, container) {
        console.log('HivePress init event detected');
        if ($(container).find('input[name="enable_price_hour"]').length) {
            PriceToggle.reinitialize();
        }
    });
    
})(jQuery);