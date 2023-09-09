function syncDonationToQb(DonationId){
	var href="?page=donor-quickbooks&syncDonation="+DonationId;
	var itemSelect=document.getElementsByName('QBItemId_'+DonationId);
	if (itemSelect.length>0){
		href+="&ItemId="+itemSelect[0].value
	}
	//console.log(href);
	window.open(href, "qb");
}