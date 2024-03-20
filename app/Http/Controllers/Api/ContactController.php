<?php

namespace App\Http\Controllers\Api;

use App\Contact;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class ContactController extends Controller
{
    //
    public function addContact(Request $request)
    {
        $request->validate([
            "name"      => "required",
            "type"      => "required",
            "address"   => "required",
            "phone"     => "required",
            "email"     => "required"
        ]);

        Contact::create([
            'name'          => $request->name,
            'type'          => $request->type,
            'address'       => $request->address,
            'phone'         => $request->phone,
            'email'         => $request->email,
            'behalf'        => $request->behalf ? $request->behalf : '',
            'created_by'    => auth()->user()->id,
            'updated_by'    => auth()->user()->id,
            'status'        => 1
        ]);

        $description =  'New contact added' . chr(10) .
                        'Name : ' . $request->name . chr(10) .
                        'Type : ' . $request->type . chr(10) .
                        'Address :' . $request->address . chr(10) .
                        'Phone : ' . $request->phone  . chr(10) .
                        'Email : ' . $request->email . chr(10) .
                        'Behalf : ' . $request->behalf;

        $this->history('contacts', 'add contact', $description);

        return response()->json([
            "status" => true,
            "message" => "Contact registered successfully"
        ]);
    }

    public function getContact()
    {
        $contacts = Contact::where('status', 1)->get();

        return response()->json([
            "status" => true,
            "message" => $contacts
        ]);
    }

    public function getContactById($id)
    {
        if (Contact::where('id', $id)->where('status', 1)->exists())
        {
            $contact = Contact::find($id);

            return response()->json([
                "status" => true,
                "message" => $contact
            ]);
        }
        else
        {
            return response()->json([
                "status" => false,
                "message" => "Contact not found"
            ], 404);
        }

    }

    public function updateContact(Request $request, $id)
    {
        if (Contact::where('id', $id)->exists())
        {
            $contact = Contact::find($id);
            $contact->update([
                'name'          => $request->name ? $request->name : $contact->name,
                'type'          => $request->type ? $request->type : $contact->type,
                'address'       => $request->address ? $request->address : $contact->address,
                'phone'         => $request->phone ? $request->phone : $contact->phone,
                'email'         => $request->email ? $request->email : $contact->email,
                'behalf'        => $request->behalf ? $request->behalf : $contact->behalf,
                'updated_at'    => date("Y-m-d H:i:s"),
                'updated_by'    => auth()->user()->id
            ]);

            return response()->json([
                "status" => true,
                "message" => "Contact updated successfully"
            ]);
        }
        else
        {
            return response()->json([
                "status" => false,
                "message" => "Contact not found"
            ], 404);
        }
    }

    public function deleteContact($id)
    {
        if (Contact::where('id', $id)->exists())
        {
            $contact = Contact::find($id);
            $contact->update([
                'status'        => 0,
                'updated_at'    => date("Y-m-d H:i:s"),
                'updated_by'    => auth()->user()->id
            ]);

            return response()->json([
                "status" => true,
                "message" => "Contact deleted successfully"
            ]);
        }
        else
        {
            return response()->json([
                "status" => false,
                "message" => "Contact not found"
            ], 404);
        }
    }

}