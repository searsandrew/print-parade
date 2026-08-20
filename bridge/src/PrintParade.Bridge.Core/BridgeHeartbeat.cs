using System.Text.Json.Serialization;

namespace PrintParade.Bridge.Core;

public sealed record BridgeHeartbeat(
    string Status,
    [property: JsonPropertyName("bridge_id")] long BridgeId);
