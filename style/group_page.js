
var group_members = {};

function formatCurrency(amount) {
    var str = ""+amount;
    while (str.length<3) str="0"+str;
    return str.substr(0,str.length-2)+","+str.substr(str.length-2);
}

//==> Add expense
$("#exp_add_btn").click(function() {
	var self = this;
    var data = {
        description: $("#add_description").val(),
        who_paid: $("#add_who_paid").val(),
        amount: Math.round($("#add_amount").val().replace(/,/,'.')*100)
    };
    $("#expense_form .input-row").after("<tr id=load-indicator><td colspan=5><div style=text-align:center><img src=/style/loading.gif alt='Please wait ...'></div></td></tr>");
    $("#add_split option").filter(":selected").each(function() {
        var m = $(this).data("member");
        data['split['+ m.member_id+']'] = m.owes;
    });
    console.log(data);
	this.setAttribute("disabled", true);
    $.post("/group/"+group.id+"/expenses", data, function(r) {
        if (r.msg) alert(r.msg);
        if (r.success) location.reload(); else { self.disabled = false; $("#load-indicator").remove(); }
    }, "json");
});

$("body").on("click", ".expense-delete", function() {
    var id = $(this).closest("[data-expense-id]").attr("data-expense-id");
    if(confirm("Are you sure?")===true) {
        $.ajax({ method: 'DELETE', url: "/group/"+group.id+"/expenses/"+id, success: function(r) {
            location.reload();
        }, dataType: "json"});
    }
    return false;
});

$("body").on("click", ".expense-copy", function() {
    var id = $(this).closest("[data-expense-id]").attr("data-expense-id");
    $.get("/group/"+group.id+"/expenses/"+id,function(r) {
        $("#add_description").val(r.expense.description);
        $("#add_who_paid").val(r.expense.who_paid);
        $("#add_amount").val(r.expense.amount/100);
        var splits = {};
        for(var i in r.splits) {
            splits[r.splits[i].member_id] = r.splits[i];
        }
        
        var split =  $("#add_split")[0];
        for(var i=0;i<split.options.length;i++) {
            var m = $(split.options[i]).data("member");
            split.options[i].selected = !!splits[m.member_id];
            m.owes = splits[m.member_id] ? splits[m.member_id].amount : null;
            updateSplitAmountText(split.options[i], m);
        }
    }, "json");
    return false;
});

function arraySum(arr) {
    var count=0;
    for (var i=arr.length; i--;) {
        count+=arr[i];
    }
    return count;
}

function calcSplitAmounts() {
    var amount = Math.round(parseFloat($("#add_amount").val().replace(/,/,'.'))*100);
    
    var split =  $("#add_split")[0];
    var selcount = $("#add_split option").filter(":selected").length;
    var amountPerUser = Math.floor(amount / selcount);
    var userAmounts = new Array(selcount);
    for(var i = 0; i < selcount; i++) userAmounts[i] = amountPerUser;
    while (arraySum(userAmounts) < amount) {
        userAmounts[Math.floor(Math.random() * selcount)]++;
    }
    var userIdx = 0;
    for(var i=0;i<split.options.length;i++) {
        var m = $(split.options[i]).data("member");
        if (amount && split.options[i].selected) {
            m.owes = userAmounts[userIdx++];
        } else {
            m.owes = null;
        }
        updateSplitAmountText(split.options[i], m);
    }
    
}
function updateSplitAmountText(option, m) {
    var t = m.display_name;
    if (m.owes) {
        t += " owes €" + (m.owes/100).toFixed(2);
    }
    option.innerText = t;
}

//==> Load and display member list
function load_members() {
    return $.get("/group/"+group.id+"/members?t="+group.readonly_token,function(r) {
        $(".memberselect").each(function() {
            add_members_to_select(this, r.members);
        });
        
        add_members_to_table($("#member_list"), r.members);
        
        group_members = {};
        r.members.forEach(function(m) { group_members[m.member_id] = m; });
    },"json");
}
function add_members_to_select(select, members) {
    $("option.member",select).remove();
    for(var i in members) {
        $("<option class=member>").attr("value",members[i].member_id)
            .text(members[i].display_name).data("member", members[i]).appendTo(select);
    }
}
function add_members_to_table($table_cont, members) {
    $table_cont.html("");
    for(var i in members) {
        var $tr = $("<tr>").attr("data-member-id",members[i].member_id)
            .data("member", members[i]);
        $("<td>").text(members[i].display_name).appendTo($tr);
        $("<td>").text(members[i].email).appendTo($tr);
        $("<td>").attr("class","member-balance").text("").appendTo($tr);
        if (members[i].user_id) {
            $("<td>").text("yes").appendTo($tr);
            if (members[i].user_id == libreSplit.userid)
                $("<td>").html("<a href='javascript:' class='member-leave'>leave</a>").appendTo($tr);
            else
                $("<td>").html("<a href='javascript:' class='member-leave'>kick</a>").appendTo($tr);
        } else {
            $("<td>").text("no").appendTo($tr);
            $("<td>").html("<a href='javascript:' class='member-show-invite'>show invite link</a> "+
                "| <a href='javascript:' class='member-rename'>rename</a>").appendTo($tr);
        }
        
        $tr.appendTo($table_cont);
    }
}

load_members().then(function() {
    $.get("/group/"+group.id+"/to_pay?t="+group.readonly_token,function(r) {
        to_pay = {};
        for(var i in r.to_pay) {
            var d = r.to_pay[i];
            if (d.debtor<d.creditor) {
                var x=d.debtor; d.debtor=d.creditor; d.creditor=x; d.debt=-d.debt;
            } else d.debt = +d.debt;
            var key = d.debtor+":"+d.creditor;
            if (!to_pay[key]) to_pay[key] = {debtor:group_members[+d.debtor], creditor:group_members[+d.creditor], debt:0};
            to_pay[key].debt += d.debt;
        }
        console.log(to_pay);
        var out="";
        var member_balances = {};
        for(var k in to_pay) {
            var d = to_pay[k];
            if (d.debt==0) continue;
            if (d.debt<0) {
                var x=d.debtor; d.debtor=d.creditor; d.creditor=x; d.debt=-d.debt;
            }
            if (libreSplit.userid && d.debtor.user_id == libreSplit.userid) {
                out+=" <span class='label label-danger'>You owe "+formatCurrency(d.debt)+" to "+d.creditor.display_name+"</span>&nbsp; ";
            } else if (libreSplit.userid && d.creditor.user_id == libreSplit.userid) {
                out+=" <span class='label label-success'>"+d.debtor.display_name+" owes you "+formatCurrency(d.debt)+"</span>&nbsp; ";
            } else {
                out+=" <span class='label label-default'>"+d.debtor.display_name+" owes "+formatCurrency(d.debt)+" to "+d.creditor.display_name +"</span>&nbsp; ";
            }
			member_balances[d.debtor.member_id] = (member_balances[d.debtor.member_id] || 0) - d.debt;
			member_balances[d.creditor.member_id] = (member_balances[d.creditor.member_id] || 0) + d.debt;
        }
		for(var id in member_balances) {
			$("tr[data-member-id="+id+"] .member-balance").text((member_balances[id]/100).toFixed(2)).css("color",member_balances[id]<0?"red":"");
		}
        $("#settleup").html(out);
        
    });
});

//==> Handle invitations
$("#invite_form").submit(function() {
    var name = $("#invite_name").val();
    if (!name) { alert("Please enter a name."); return false; }
    $.post("/group/"+group.id+"/members", {
        display_name: name
    },function(r) {
        if (r.success) {
            $("#invite_name").val("");
            load_members();
            show_invite_modal(r.link);
        }
    },"json").always(function() {
        $("#invite_form input").attr("disabled",false);
    });
    $("#invite_form input").attr("disabled",true);
    return false;
});

$("body").on("click", "a.member-show-invite", function() {
    var member = $(this).closest("[data-member-id]").data("member");
    show_invite_modal(member.link);
});
$("body").on("click", "a.member-rename", function() {
    var member = $(this).closest("[data-member-id]").data("member");
    var new_name;
    if (( new_name=prompt("Rename member", member.display_name) )) {
        $.post("/group/"+group.id+"/members/"+member.member_id, { display_name: new_name }, function(r) {
            if (r.msg) alert(r.msg);
            if (r.success) {
                load_members();
            }
        }, "json");
    }
});
$("body").on("click", "a.member-leave", function() {
    var member = $(this).closest("[data-member-id]").data("member");
    if (confirm("Are you sure?")===true) {
        $.post("/group/"+group.id+"/members/"+member.member_id+"/kick", {}, function(r) {
            if (r.msg) alert(r.msg);
            if (r.success) {
                load_members();
            }
        }, "json");
    }
});
function switchNotifications(new_state) {
    $.post("/group/"+group.id+"", { 'notifications': new_state }, function(r) {
        if (r.msg) alert(r.msg);
        if (r.success) {
            location=location;
        }
    }, "json");
}

function show_invite_modal(link) {
    $("#modal_invited_success").modal("show");
    $("#modal_invited_success p.the-link").html("<input class=form-control type=text readonly onclick=this.select() value='"+link+"'>");
    $("#modal_invited_success p.the-link-mail").html("<a href='mailto:?subject=LibreSplit+Invitation&body="+escape(link)+"'>Click here to open your mail program to send the link via email</a>");
}

function deleteGroup() {
    if (prompt("Are you sure you want to delete group "+group.name+"?\nType 'YES' to confirm.","")=="YES") {
        $.ajax({
            method: "DELETE",
            url: "/group/"+group.id,
            success: function() {
                alert("Group deleted");
                location="/groups";
            }
        });
    }
}

$("#search_box").keyup(function() {
    var searchTerm = new RegExp(this.value.toLowerCase());
    var matches=0;
    $("#expense_form table tr").each(function(i,x){
        var firstCol=x.querySelector("td");
        if (firstCol) {
            if (searchTerm.test(firstCol.innerText.toLowerCase())) {
                x.style.display= "";
                matches++;
            }else {
                x.style.display = "none";
            }
        }
    });
    $("#search_matches").text(""+matches+" Trefffer");
});

