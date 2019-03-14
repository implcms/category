$(document).ready(()=>{
    $('.dd').map((index,item)=>{
        $(item).nestable({
            group: $(item).attr('data-id')
        });
        $(item).on('change', function(e) {
            let data = {'impl':{'api':'category@main.reorder'}};
            data.json = JSON.stringify($(item).nestable('serialize'));
            Impl.fetch(data).then((res)=>{
                Impl.showToast(res);
            }).catch(err=>{
            });
        });
    });
});