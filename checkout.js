jQuery(function($){

    console.log("EDA Checkout JS Loaded");


    // تحويل حقل المدينة إلى select




    function loadAreas(govId){
        $.post(EDA_AJAX.url,{action:'eda_get_areas2', gov:govId}, function(res){
            var select = $('#billing_district');
            select.empty().append('<option value="">Select Area</option>');
            if(res.success){
                $.each(res.data.result,function(id,name){
                    select.append('<option value="'+id+'">'+name+'</option>');
                });
                var fee = Object.values(res.data.result2)[0];
                            console.log(fee);

                $.post(EDA_AJAX.url,{
                    action:'eda_set_delivery_fee',
                    fee:fee
                }, function(){
                    $('body').trigger('update_checkout');
                });
            } else {
                alert(res.data.message);
            }
        });
    }

    $('#billing_city').on('change', function(){
        var govId = $(this).val();
        if(govId > 0){
            loadAreas(govId);
        }
    });

});
